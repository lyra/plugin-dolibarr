<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra Collect plugin for Dolibarr. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   http://www.gnu.org/licenses/gpl.html GNU General Public License (GPL v3)
 */

dol_include_once('/lyra/class/sdk-autoload.php');
dol_include_once('/lyra/core/modules/modLyra.class.php');

function lyraCanUseCurrency()
{
    global $conf;

    $currency = \Lyranetwork\Lyra\Sdk\Form\Api::findCurrencyByAlphaCode($conf->currency);

    return $currency != null;
}

/**
 * Prepare gateway request data.
 *
 * @param   string  $ref      Order id to find the object in database
 * @param   string  $newamount   Amount to pay.
 */
function lyraPrepareRequest($ref, $newAmount = null)
{
    global $conf;

    $lyraRequest = new \Lyranetwork\Lyra\Sdk\Form\Request();

    // Admin configuration parameters.
    $configParams = array(
        'site_id', 'key_test', 'key_prod', 'ctx_mode', 'available_languages',
        'capture_delay', 'validation_mode', 'redirect_enabled', 'redirect_success_timeout',
        'redirect_success_message', 'redirect_error_timeout', 'redirect_error_message',
        'return_mode'
    );

    foreach ($configParams as $name) {
        $key = "LYRA_" . strtoupper($name);
        $lyraRequest->set($name, null !== getDolGlobalString($key) ? getDolGlobalString($key) : null);
    }

    $lyraRequest->set('sign_algo', modLyra::SIGN_ALGO);
    $lyraRequest->set('platform_url', modLyra::GATEWAY_URL);
    $lyraRequest->set('url_return', dol_buildpath(modLyra::PROCESS_URL, 2));

    $language = getDolGlobalString('MAIN_LANG_DEFAULT') ? strtoupper(substr(getDolGlobalString('MAIN_LANG_DEFAULT'), 0, 2)) : getDolGlobalString('LYRA_LANGUAGE');
    $lyraRequest->set('language', $language);

    // Get the currency to use.
    $currency = \Lyranetwork\Lyra\Sdk\Form\Api::findCurrencyByAlphaCode($conf->currency);
    $lyraRequest->set('currency', $currency->getNum());

    // Set the amount to pay.
    if ($newAmount) {
        $amount = price2num($newAmount, 'MT');
    } else {
        $amount = price2num(GETPOST("newamount"), 'MT');
    }

    $lyraRequest->set('amount', $currency->convertAmountToInteger($amount));

    $dolibarrVersion = getDolGlobalString('MAIN_VERSION_FIRST_INSTALL') ? getDolGlobalString('MAIN_VERSION_FIRST_INSTALL') : getDolGlobalString('MAIN_VERSION_LAST_INSTALL');
    $contrib = modLyra::CMS_IDENTIFIER . '_' . modLyra::PLUGIN_VERSION . '/' . $dolibarrVersion . '/' . \Lyranetwork\Lyra\Sdk\Form\Api::shortPhpVersion();
    $lyraRequest->set('contrib', $contrib);

    // Check if order exist and get Order_id, cust_id and taxe rate.
    $result = lyraCheckOrder($ref);

    $lyraRequest->set('order_id', $result['order_id']);

    if (getDolGlobalString('MAIN_INFO_SOCIETE_COUNTRY') == '70:CO:Colombia') {
        // Tax rate for Colombia.
        $lyraRequest->set('tax_rate', $result['tax_rate']);
    }

    // Customer information.
    $fullName = lyraSplitName(GETPOST('shipToName', 'alpha'));

    $lyraRequest->set('cust_email', GETPOST('email', 'alpha'));
    $lyraRequest->set('cust_id', $result['cust_id']);
    $lyraRequest->set('cust_first_name', $fullName ['first_name']);
    $lyraRequest->set('cust_last_name', $fullName ['last_name']);
    $lyraRequest->set('cust_address', GETPOST('shipToStreet', 'alpha'));
    $lyraRequest->set('cust_address2', GETPOST('shipToStreet2', 'alpha'));
    $lyraRequest->set('cust_zip', GETPOST('shipToZip', 'alpha'));
    $lyraRequest->set('cust_city', GETPOST('shipToCity', 'alpha'));
    $lyraRequest->set('cust_country', GETPOST('shipToCountryCode', 'alpha'));
    $lyraRequest->set('cust_phone', GETPOST('phoneNum', 'alpha'));
    $lyraRequest->set('cust_status', $result['cust_status']);

    $lyraRequest->addExtInfo('full_tag', $result['full_tag']);

    return $lyraRequest;
}

/**
 * Print redirect form with object data to call payment gateway.
 *
 * @param   string       $ref         Order id
 */
function lyraRedirectForm($ref)
{
    $lyraRequest = lyraPrepareRequest($ref);

    $paramsToLog = $lyraRequest->getRequestFieldsArray(true);
    lyraSyslog('Data to be sent to payment gateway :' . json_encode($paramsToLog));

    print '<form action="' . $lyraRequest->get('platform_url') . '" method="POST" id="lyra_form">';
    print $lyraRequest->getRequestHtmlFields();
    print '</form>' . "\n";
    print '<script type="text/javascript" language="javascript">' . "\n";
    print '    $(document).ready(function() {';
    print '        $("#lyra_form").submit();' . "\n";
    print '    })';
    print '</script>' . "\n";
}

function getLyraFormToken($ref, $amount = null)
{
    global $langs;

    $privateKey = getLyraPrivateKey();
    if (! $privateKey) {
        lyraSyslog("Cannot create form token: private key is not configured.", LOG_ERR);

        if (getDolGlobalString('LYRA_CTX_MODE') === 'TEST') {
            dol_htmloutput_errors($langs->trans('Private key is mandatory.'), '');
        }

        return false;
    }

    $lyraRequest = lyraPrepareRequest($ref, $amount);

    $validationMode = ((getLyraEscapeVar($lyraRequest, 'validation_mode') == 0) ? 'NO' : 'YES');
    $currency = \Lyranetwork\Lyra\Sdk\Form\Api::findCurrencyByNumCode(getLyraEscapeVar($lyraRequest, 'currency'));

    $params = array(
        'amount' => getLyraEscapeVar($lyraRequest, 'amount'),
        'currency' => $currency->getAlpha3(),
        'orderId' => getLyraEscapeVar($lyraRequest, 'order_id'),
        'customer' => array(
            'email' => getLyraEscapeVar($lyraRequest, 'cust_email'),
            'reference' => getLyraEscapeVar($lyraRequest, 'cust_id'),
            'billingDetails' => array(
                'address' => getLyraEscapeVar($lyraRequest, 'cust_address'),
                'address2' => getLyraEscapeVar($lyraRequest, 'cust_address2'),
                'city' => getLyraEscapeVar($lyraRequest, 'cust_city'),
                'country' => getLyraEscapeVar($lyraRequest, 'cust_country'),
                'firstName' => getLyraEscapeVar($lyraRequest, 'cust_first_name'),
                'lastName' => getLyraEscapeVar($lyraRequest, 'cust_last_name'),
                'language' => getLyraEscapeVar($lyraRequest, 'language'),
                'phoneNumber' => getLyraEscapeVar($lyraRequest, 'cust_phone'),
                'zipCode' => getLyraEscapeVar($lyraRequest, 'cust_zip')
            )
        ),
        'taxRate' => getLyraEscapeVar($lyraRequest, 'tax_rate'),
        'transactionOptions' => array(
            'cardOptions' => array(
                'captureDelay' => getLyraEscapeVar($lyraRequest, 'capture_delay'),
                'manualValidation' => $validationMode,
                'paymentSource' => 'EC'
            )
        ),
        'metadata' => array(
            'full_tag' => getLyraEscapeVar($lyraRequest, 'full_tag', true)
        ),
        'contrib' => getLyraEscapeVar($lyraRequest, 'contrib')
    );

    if (getDolGlobalString('LYRA_RETRY_NUMBER')) {
        $params['transactionOptions']['cardOptions']['retry'] = getDolGlobalString('LYRA_RETRY_NUMBER');
    }

    $formToken = false;

    try {
        $client = new \Lyranetwork\Lyra\Sdk\Rest\Api(
            modLyra::REST_URL,
            getDolGlobalString('LYRA_SITE_ID'),
            $privateKey
        );

        $data = json_encode($params);
        lyraSyslog("Creating form token for object #{$ref} with parameters: {$data}");

        $response = $client->post('V4/Charge/CreatePayment', $data);
        if ($response) {
            // Check if there are some errors.
            if ($response['status'] !== 'SUCCESS') {
                $msg = "Error while creating payment form token for object #{$ref}: " . $response['answer']['errorMessage'] . ' (' . $response['answer']['errorCode'] . ').';
                if (isset($response['answer']['detailedErrorMessage']) && ! empty($response['answer']['detailedErrorMessage'])) {
                    $msg .= ' Detailed message: ' . $response['answer']['detailedErrorMessage'] .' (' . $response['answer']['detailedErrorCode'] . ').';
                }

                if (getDolGlobalString('LYRA_CTX_MODE') === 'TEST') {
                    dol_htmloutput_errors($msg, '');
                }
            } else {
                // Extract the form token.
                $formToken = $response['answer']['formToken'];
                lyraSyslog("Form token created successfully for object #{$ref}.");
            }
        }
    } catch (Exception $e) {
        lyraSyslog($langs->trans($e->getMessage()), LOG_ERR);
        setEventMessages($langs->trans($e->getMessage()), null, 'errors');
    }

    return $formToken;
}

/**
 * Print embedded form with object data to call payment gateway.
 *
 * @param   string       $ref         Order id
 * @param   int          $popin       To verify if it is an embedded form or pop-in
 */
function lyraEmbeddedForm($ref, $popin, $amount = null)
{
    global $conf, $langs;

    $formToken = getLyraFormToken($ref, $amount);
    if (! $formToken) {
        throw new Exception($langs->trans('LYRA_REST_ERROR_MSG'));
     }

    $returnUrl = dol_buildpath(modLyra::PROCESS_URL, 2);
    $staticUrl = modLyra::STATIC_URL;
    $dolibarrLanguage = substr(getDolGlobalString('MAIN_LANG_DEFAULT'), 0, 2);
    $theme = getDolGlobalString('LYRA_EMBEDDED_THEME');

    $smartformAttributes = '';
    if ($popin) {
        $smartformAttributes .= 'kr-popin';
    } else {
        if (getDolGlobalString('LYRA_CARD_INFO_MODE') == modLyra::MODE_EMBEDDED_EXT_WITH_LOGOS) {
            $smartformAttributes .= ' kr-card-form-expanded';
        } elseif (getDolGlobalString('LYRA_CARD_INFO_MODE') == modLyra::MODE_EMBEDDED_EXT_WITHOUT_LOGOS) {
            $smartformAttributes .= ' kr-card-form-expanded kr-no-card-logo-header';
        }
    }

    print '<head>';
    print '     <meta name="viewport" ';
    print '         content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />';
    print '     <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />';
    print '     <meta http-equiv="X-UA-Compatible" content="IE=edge" />  ';

    print '     <script>';
    print '         var LYRA_LANGUAGE = "' . $dolibarrLanguage . '";';
    print '         var LYRA_COMPACT = "' . getDolGlobalString('LYRA_COMPACT_MODE') . '";';
    print '     </script>';

    print '     <!-- Javascript library. Should be loaded in head section -->';
    print '     <script ';
    print '         src="' . $staticUrl . 'js/krypton-client/V4.0/stable/kr-payment-form.min.js" ';
    print '         kr-public-key="' . getLyraPublicKey() . '"';
    print '         kr-post-url-success="' . $returnUrl . '"';
    print '         kr-post-url-refused="' . $returnUrl . '">';
    print '     </script>';

    print '     <!-- Theme and plugins. should be loaded after the javascript library -->';
    print '     <!-- not mandatory but helps to have a nice payment form out of the box. -->';
    print '     <link rel="stylesheet" ';
    print '         href="' . $staticUrl . 'js/krypton-client/V4.0/ext/' . $theme . '-reset.css">';
    print '     <script ';
    print '         src="' . $staticUrl . 'js/krypton-client/V4.0/ext/' . $theme . '.js">';
    print '     </script> ';
    print '     <script ';
    print '         src="' . dol_buildpath('/lyra/js/rest.js', 2). '">';
    print '     </script>';
    print '</head>';

    print '<table id="dolpaymenttable" summary="Payment form" class="center"'. ($popin ? ' style="display: none;"' : '' ) . '>
            <tbody>';
    print '     <tr>';
    print '         <td>';
    print '         <div class="kr-smart-form" ' . $smartformAttributes;
    print '             kr-language= "' . $dolibarrLanguage . '" ';
    print '             kr-form-token="' . $formToken . '">';
    print '             <!-- payment form fields -->';
    print '             <div class="kr-pan"></div>';
    print '             <div class="kr-expiry"></div>';
    print '             <div class="kr-security-code"></div>';

    print '             <!-- payment form submit button -->';
    print '             <button class="kr-payment-button"></button>';

    print '             <!-- error zone -->';
    print '             <div class="kr-form-error"></div>';
    print '         </div>';
    print '         </td>';
    print '     </tr>';
    print ' </tbody>';
    print '</table>';
}

/**
 * Check if object exist (order, invoice, donation, etc.)
 *
 * @return  array Customer info and taxe rate
 */
function lyraCheckOrder($ref)
{
    require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
    require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
    require_once DOL_DOCUMENT_ROOT . '/don/class/don.class.php';

    global $db;

    $source = (GETPOST('s', 'alpha') ? GETPOST('s', 'alpha') : GETPOST('source', 'alpha'));

    $object = null;
    $orderId = '';
    $taxRate = '';
    $custId = '';
    $fullTag = '';
    $custStatus = 'PRIVATE';

    switch ($source) {
        case 'invoice':
            $object = new Facture($db);

            break;

        case 'order':
            $object = new Commande($db);

            break;

        case 'donation':
            $object = new Don($db);

            break;

        case 'contractline':
            $object = new ContratLigne($db);

            break;

        case 'membersubscription':
            $object = null;

            break;

        default:
            // IVA (VAT tax) is applied only for Colombia.
            $taxRate = lyraFindTaxRate();
            $fullTag = GETPOST('fulltag', 'alpha');

            break;
    }

    if ($object) {
        if (! empty($ref)) {
            $result = $object->fetch('', $ref); // Find by ref.
        } else {
            $result = $object->fetch($ref); // Find by rowId (Donation).
        }

        // If object was found (Facture, order, donation...).
        if ($result) {
            if (GETPOST('fulltag', 'alpha')) {
                $fullTag = GETPOST('fulltag', 'alpha');
            } elseif ($source == 'invoice') {
                $fetchResult = $object->fetch_thirdparty($object->socid);
                $fullTag = 'INV=' . $object->id . '.CUS=' . $object->thirdparty->id;
            }

            $orderId = $ref;

            // IVA (VAT tax) is applied only for Colombia.
            $taxRate = lyraFindTaxRate();

            // Customer information is consulted.
            $societe = new Societe($db);
            $result = $societe->fetch('', GETPOST('shipToName', 'alpha'), null, null, null, null, null, null, null, null, GETPOST('email', 'alpha'));
            if ($societe->code_client) {
                $custStatus = 'PRIVATE';
                $custId = $societe->code_client;
            } elseif ($societe->code_fournisseur) {
                $custStatus = 'COMPANY';
                $custId = $societe->code_fournisseur;
            }
        } else {
            print "<h1>Error: Object with ID #$ref was not found in database.</h1>";
            exit;
        }
    }

    return array('order_id' => $orderId, 'tax_rate' => $taxRate, 'cust_id' => $custId, 'cust_status' => $custStatus, 'full_tag' => $fullTag);
}

/**
 * Return Tax rate configured on Dolibarr for Colombia.
 *
 * @return  $taxRate Tax rate configured in Dolibarr
 */
function lyraFindTaxRate()
{
    global $db;

    $taxRate = null;
    if (getDolGlobalString('MAIN_INFO_SOCIETE_COUNTRY') == '70:CO:Colombia') {
        $sql = 'SELECT taux as vat_rate';
        $sql .= ' FROM ' . MAIN_DB_PREFIX . 'c_tva as t, ' . MAIN_DB_PREFIX . 'c_country as c';
        $sql .= " WHERE t.active=1 AND t.fk_pays = c.rowid AND c.code='CO' AND t.taux <> 0";
        $sql .= ' ORDER BY t.taux ASC';
        $resql = $db->query($sql);
        if ($resql) {
            $num = $db->num_rows($resql);
            if ($num) {
                for ($i = 0; $i < $num; $i++) {
                    $obj = $db->fetch_object($resql);
                    $vat_rates[$i] = $obj->vat_rate;
                }
            }
        }

        $taxRate = $vat_rates[0];
    }

    return $taxRate;
}

/**
 * Take the fullname from Dolibarr and return it as first name and last name.
 *
 * @param   string  $full_name  All the params to make the request to payment gateway
 * @return  array               Array with full name and last name
 */
function lyraSplitName($full_name)
{
    $tokens = explode(' ', trim($full_name));
    $names = array();

    // Words of compound lastnames.
    $specialTokens = array('da', 'de', 'del', 'la', 'las', 'los', 'mac', 'mc', 'van', 'von', 'y', 'i', 'san', 'santa');

    $prev = '';
    foreach($tokens as $token) {
        $_token = strtolower($token);
        if (in_array($_token, $specialTokens)) {
            $prev .= "$token ";
        } else {
            $names[] = $prev . $token;
            $prev = '';
        }
    }

    $numNombres = count($names);
    $nombres = '';
    $apellidos = '';
    switch ($numNombres) {
        case 0:
            $nombres = '';

            break;

        case 1:
            $nombres = $names[0];

            break;

        case 2:
            $nombres    = $names[0];
            $apellidos  = $names[1];

            break;

        case 3:
            $nombres = $names[0];
            $apellidos = $names[1] . ' ' . $names[2];

            break;

        case 4:
            $nombres = $names[0] . ' ' . $names[1];
            $apellidos = $names[2] . ' ' . $names[3];

            break;

        default:
            $nombres = $names[0] . ' ' . $names[1];
            unset($names[0]);
            unset($names[1]);
            $apellidos = implode(' ', $names);

            break;
    }

    $nombres    = mb_convert_case($nombres, MB_CASE_TITLE, 'UTF-8');
    $apellidos  = mb_convert_case($apellidos, MB_CASE_TITLE, 'UTF-8');

    return array('first_name' => $nombres, 'last_name' => $apellidos);
}

function getLyraEscapeVar($request, $var, $isExtInfo = false)
{
    if ($isExtInfo) {
        $var = 'vads_ext_info_' . $var;
    }

    $value = $request->get($var);

    if (empty($value)) {
        return null;
    }

    return $value;
}

/**
 * Show header.
 *
 * @param    string    $title    Title
 * @param    string    $head     More header to add
 * @return   void
 */
function llxHeaderLyra($title, $head = '')
{
    global $conf, $langs, $mysoc;

    header('Content-type: text/html; charset=' . $conf->file->character_set_client);

    $appli = getDolGlobalString('MAIN_APPLICATION_TITLE') ? getDolGlobalString('MAIN_APPLICATION_TITLE') : 'Dolibarr';

    print '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">';
    print "\n";
    print "<html>\n";
    print "<head>\n";
    print '<meta name="robots" content="noindex,nofollow">' . "\n";
    print '<meta name="keywords" content="dolibarr,payment,online">' . "\n";
    print '<meta name="description" content="Welcome on ' . $appli . ' online payment form">' . "\n";
    print "<title>" . $title . "</title>\n";

    if ($head) {
        print $head . "\n";
    }

    if (getDolGlobalString('ONLINE_PAYMENT_CSS_URL')) {
        print '<link rel="stylesheet" type="text/css" href="' . getDolGlobalString('ONLINE_PAYMENT_CSS_URL') . '?lang=' . $langs->defaultlang . '">' . "\n";
    } else {
        print '<link rel="stylesheet" type="text/css" href="' . DOL_URL_ROOT . $conf->css . '?lang=' . $langs->defaultlang . '">' . "\n";
        print '<style type="text/css">';
        print '.CTableRow1      { margin: 1px; padding: 3px; font: 12px verdana,arial; background: #e6E6eE; color: #000000; -moz-border-radius-topleft:6px; -moz-border-radius-topright:6px; -moz-border-radius-bottomleft:6px; -moz-border-radius-bottomright:6px;}';
        print '.CTableRow2      { margin: 1px; padding: 3px; font: 12px verdana,arial; background: #FFFFFF; color: #000000; -moz-border-radius-topleft:6px; -moz-border-radius-topright:6px; -moz-border-radius-bottomleft:6px; -moz-border-radius-bottomright:6px;}';
        print '</style>';
    }

    if ($conf->use_javascript_ajax) {
        print '<!-- Includes for JQuery (Ajax library) -->' . "\n";
        print '<link rel="stylesheet" type="text/css" href="' . DOL_URL_ROOT . '/includes/jquery/plugins/jnotify/jquery.jnotify-alt.min.css" />' . "\n";

        // Output standard javascript links.
        $ext = '.js';
        print '<!-- Includes JS for JQuery -->' . "\n";
        print '<script type="text/javascript" src="' . DOL_URL_ROOT . '/includes/jquery/js/jquery.min' . $ext . '"></script>' . "\n";
    }

    $favicon = DOL_URL_ROOT . '/theme/dolibarr_logo_256x256.png';
    if (! empty($mysoc->logo_squarred_mini)) {
        $favicon = DOL_URL_ROOT . '/viewimage.php?cache=1&modulepart=mycompany&file=' . urlencode('logos/thumbs/' . $mysoc->logo_squarred_mini);
    }

    if (getDolGlobalString('MAIN_FAVICON_URL')) {
        $favicon = getDolGlobalString('MAIN_FAVICON_URL');
    }

    if (empty($conf->dol_use_jmobile)) {
        print '<link rel="shortcut icon" type="image/x-icon" href="' . $favicon . '"/>' . "\n"; // Not required into an Android webview.
    }

    print "</head>\n";
    print '<body style="margin: 20px;">' . "\n";
}

/**
 * Get REST API private key.
 *
 * @return   string
 */
function getLyraPrivateKey()
{
    if (getDolGlobalString('LYRA_CTX_MODE') === 'TEST') {
        return getDolGlobalString('LYRA_REST_API_KEY_TEST') ?? null;
    }

    return getDolGlobalString('LYRA_REST_API_KEY_PROD') ?? null;
}

/**
 * Get REST API public key.
 *
 * @return   string
 */
function getLyraPublicKey()
{
    if (getDolGlobalString('LYRA_CTX_MODE') === 'TEST') {
        return getDolGlobalString('LYRA_REST_API_PUBLIC_KEY_TEST') ?? null;
    }

    return getDolGlobalString('LYRA_REST_API_PUBLIC_KEY_PROD') ?? null;
}

/**
 * Get REST API HMAC-SHA-256 key.
 *
 * @return   string
 */
function getLyraReturnKey()
{
    if (getDolGlobalString('LYRA_CTX_MODE') === 'TEST') {
        return getDolGlobalString('LYRA_REST_API_HMAC_256_KEY_TEST') ?? null;
    }

    return getDolGlobalString('LYRA_REST_API_HMAC_256_KEY_PROD') ?? null;
}

/**
 * Check if payment with embedded fields is configured.
 *
 * @return   boolean
 */
function lyraEmbeddedPayment($value = null)
{
    if (! $value) {
        $value = getDolGlobalString('LYRA_CARD_INFO_MODE');
    }

    return in_array($value,
        [
            modLyra::MODE_EMBEDDED,
            modLyra::MODE_EMBEDDED_EXT_WITH_LOGOS,
            modLyra::MODE_EMBEDDED_EXT_WITHOUT_LOGOS
        ]
    );
}

/**
 * Check if field value respects a given regex.
 *
 * @return   boolean
 */
function checkRegex($regex, $value)
{
    if (preg_match('/'. $regex . '/i', $value)) {
        return true;
    }

    return false;
}

/**
 * Print the specified message in dolibarr_lyra.log file.
 *
 * @param   string    $message    Message to be printed in log file
 * @param   string    $level      Level at which the message will be printed
 * @return  void
 */
function lyraSyslog($message, $level = LOG_INFO)
{
    $version = modLyra::PLUGIN_VERSION;

    $log = '';
    $log .= 'lyra ' . $version;
    $log .= ' : ' . $message;

    dol_syslog($log, $level, 0, '_lyra');
}