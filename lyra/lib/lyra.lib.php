<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra Collect plugin for Dolibarr. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   http://www.gnu.org/licenses/gpl.html GNU General Public License (GPL v3)
 */

/**
 * Prepare gateway request data.
 *
 * @param   string  $ref      Order id to find the object in database
 */
function lyraPrepareRequest($ref)
{
    dol_include_once('/lyra/class/LyraRequest.php');
    dol_include_once('/lyra/core/modules/modLyra.class.php');

    global $conf;

    $lyra_request = new LyraRequest();

    // Admin configuration parameters.
    $config_params = array(
        'site_id', 'key_test', 'key_prod', 'ctx_mode', 'available_languages',
        'capture_delay', 'validation_mode', 'theme_config', 'shop_name', 'shop_url',
        'redirect_enabled', 'redirect_success_timeout', 'redirect_success_message',
        'redirect_error_timeout', 'redirect_error_message', 'return_mode', 'url_return'
    );

    foreach ($config_params as $name) {
        $key = "LYRA_" . strtoupper($name);
        $lyra_request->set($name, $conf->global->$key);
    }

    $lyra_request->set('sign_algo', modLyra::getDefault('SIGN_ALGO'));
    $lyra_request->set('platform_url', $conf->global->LYRA_GATEWAY_URL);

    $language = $conf->global->MAIN_LANG_DEFAULT ? strtoupper(substr($conf->global->MAIN_LANG_DEFAULT, 0, 2)) : $conf->global->LYRA_LANGUAGE;
    $lyra_request->set('language', $language);

    // Get the currency to use.
    $currency = LyraApi::findCurrencyByAlphaCode($conf->currency);
    $lyra_request->set('currency', $currency->getNum());

    // Set the amount to pay.
    $amount = price2num(GETPOST("newamount"), 'MT');
    $lyra_request->set('amount', $currency->convertAmountToInteger($amount));

    $dolibarr_version = (! empty($conf->global->MAIN_VERSION_FIRST_INSTALL) ? $conf->global->MAIN_VERSION_FIRST_INSTALL : $conf->global->MAIN_VERSION_LAST_INSTALL);
    $contrib = modLyra::getDefault('CMS_IDENTIFIER') . '_' . modLyra::getDefault('PLUGIN_VERSION') . '/' . $dolibarr_version . '/' . LyraApi::phpVersion();
    $lyra_request->set('contrib', $contrib);

    // Check if order exist and get Order_id, cust_id and taxe rate.
    $result = lyraCheckOrder($ref);

    $lyra_request->set('order_id', $result['order_id']);

    if ($conf->global->MAIN_INFO_SOCIETE_COUNTRY == '70:CO:Colombia') {
        // Tax rate for Colombia.
        $lyra_request->set('tax_rate', $result['tax_rate']);
    }

    // Customer information.
    $fullname = lyraSplitName(GETPOST('shipToName', 'alpha'));

    $lyra_request->set('cust_email', GETPOST('email', 'alpha'));
    $lyra_request->set('cust_id', $result['cust_id']);
    $lyra_request->set('cust_first_name', $fullname ['first_name']);
    $lyra_request->set('cust_last_name', $fullname ['last_name']);
    $lyra_request->set('cust_address', GETPOST('shipToStreet', 'alpha'));
    $lyra_request->set('cust_address2', GETPOST('shipToStreet2', 'alpha'));
    $lyra_request->set('cust_zip', GETPOST('shipToZip', 'alpha'));
    $lyra_request->set('cust_city', GETPOST('shipToCity', 'alpha'));
    $lyra_request->set('cust_country', GETPOST('shipToCountryCode', 'alpha'));
    $lyra_request->set('cust_phone', GETPOST('phoneNum', 'alpha'));
    $lyra_request->set('cust_status', $result['cust_status']);

    $lyra_request->addExtInfo('full_tag', $result['full_tag']);

    return $lyra_request;
}

/**
 * Print redirect form with object data to call payment gateway.
 *
 * @param   string       $ref         Order id
 */
function lyraRedirectForm($ref)
{
    $lyra_request = lyraPrepareRequest($ref);

    print '<form action="' . $lyra_request->get('platform_url') . '" method="POST" name="lyra_form">';
    print $lyra_request->getRequestHtmlFields();
    print '</form>' . "\n";
    print '<script type="text/javascript" language="javascript">' . "\n";
    print '    document.lyra_form.submit();' . "\n";
    print '</script>' . "\n";
}



function getLyraFormToken($ref) {
    global $conf, $langs;

    $lyra_request = lyraPrepareRequest($ref);

    $validation_mode = ((getLyraEscapeVar($lyra_request, 'validation_mode') == 0) ? 'NO' : 'YES');
    $currency = LyraApi::findCurrencyByNumCode(getLyraEscapeVar($lyra_request, 'currency'));

    $params = array(
        'amount' => getLyraEscapeVar($lyra_request, 'amount'),
        'currency' => $currency->getAlpha3(),
        'orderId' => getLyraEscapeVar($lyra_request, 'order_id'),
        'customer' => array(
            'email' => getLyraEscapeVar($lyra_request, 'cust_email'),
            'reference' => getLyraEscapeVar($lyra_request, 'cust_id'),
            'billingDetails' => array(
                'address' => getLyraEscapeVar($lyra_request, 'cust_address'),
                'address2' => getLyraEscapeVar($lyra_request, 'cust_address2'),
                'city' => getLyraEscapeVar($lyra_request, 'cust_city'),
                'country' => getLyraEscapeVar($lyra_request, 'cust_country'),
                'firstName' => getLyraEscapeVar($lyra_request, 'cust_first_name'),
                'lastName' => getLyraEscapeVar($lyra_request, 'cust_last_name'),
                'language' => getLyraEscapeVar($lyra_request, 'language'),
                'phoneNumber' => getLyraEscapeVar($lyra_request, 'cust_phone'),
                'zipCode' => getLyraEscapeVar($lyra_request, 'cust_zip')
            )
        ),
        'taxRate' => getLyraEscapeVar($lyra_request, 'tax_rate'),
        'transactionOptions' => array(
            'cardOptions' => array(
                'captureDelay' => getLyraEscapeVar($lyra_request, 'capture_delay'),
                'manualValidation' => $validation_mode,
                'paymentSource' => 'EC'
            )
        ),
        'metadata' => array(
            'full_tag' => getLyraEscapeVar($lyra_request, 'full_tag', true)
        ),
        'contrib' => getLyraEscapeVar($lyra_request, 'contrib')
    );

    dol_include_once('/lyra/class/LyraRest.php');

    $return = false;
    try {
        $client = new LyraRest(
            $conf->global->LYRA_REST_API_URL,
            $conf->global->LYRA_SITE_ID,
            getLyraPrivateKey()
        );

        $response = $client->post('V4/Charge/CreatePayment', json_encode($params));
        if ($response) {
            // Check if there are some errors.
            if ($response['status'] !== 'SUCCESS') {
                // An error occured, throw an exception.
                $errorCode = $response['answer']['errorCode'];
                $errorMessage = $response['answer']['errorMessage'];
                lyra_syslog('Lyra Module (lyra.lib.php): Code =  ' . $errorCode . '; Message= ' . $errorMessage, $ref, LOG_ERR);
            } else {
                // Extract the form token.
                $return  = $response['answer']['formToken'];
            }
        }

    } catch (Exception $e) {
        lyra_syslog($langs->trans($e->getMessage()), $ref, LOG_ERR);
        setEventMessages($langs->trans($e->getMessage()), null, 'errors');
    }

    return $return;
}

/**
 * Print embedded form with object data to call payment gateway.
 *
 * @param   string       $ref         Order id
 * @param   int          $popin       To verify if it is an embedded form or pop-in
 */
function lyraEmbeddedForm($ref, $popin)
{
    global $conf;

    $formToken = getLyraFormToken($ref);

    if ($formToken) {
        $post_url_success = $conf->global->LYRA_REST_API_URL_CHECK;
        $kr_js_endpoint = $conf->global->LYRA_REST_API_CLIENT_STATIC_URL;
        $language_dolibarr = substr($conf->global->MAIN_LANG_DEFAULT, 0, 2);
        $kr_public_key = getLyraPublicKey();

        print '<head>';
        print '     <meta name="viewport" ';
        print '         content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />';
        print '     <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />';
        print '     <meta http-equiv="X-UA-Compatible" content="IE=edge" />  ';

        print '     <script>';
        print '         var LYRA_LANGUAGE = "' . $language_dolibarr . '"';
        print '     </script>';

        print '     <!-- Javascript library. Should be loaded in head section -->';
        print '     <script ';
        print '         src="' . $kr_js_endpoint . 'js/krypton-client/V4.0/stable/kr-payment-form.min.js" ';
        print '         kr-public-key="' . $kr_public_key . '"';
        print '         kr-post-url-success="' . $post_url_success . '">';
        print '     </script>';

        print '     <!-- Theme and plugins. should be loaded after the javascript library -->';
        print '     <!-- not mandatory but helps to have a nice payment form out of the box. -->';
        print '     <link rel="stylesheet" ';
        print '         href="' . $kr_js_endpoint . 'js/krypton-client/V4.0/ext/classic-reset.css">';
        print '     <script ';
        print '         src="' . $kr_js_endpoint . 'js/krypton-client/V4.0/ext/classic.js">';
        print '     </script> ';
        print '     <script ';
        print '            src="' . DOL_URL_ROOT . '/lyra/js/rest.js">';
        print '           </script>';
        print '</head>';

        print '<table id="dolpaymenttable" summary="Payment form" class="center">
                <tbody>';
        print '     <tr>';
        print '         <td>';
        print '         <div class="kr-embedded" ';

        if ($popin) {
            print '    kr-popin ';
        }

        print '             kr-language= "' . $language_dolibarr . '" ';
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

        $log = (($popin) ? ' POP-IN' : '');
        lyra_syslog('PAYMENT GATEWAY EMBEDDED' . $log, $ref, LOG_INFO);
    }
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
    $order_id = '';
    $tax_rate = '';
    $cust_id = '';
    $full_tag = '';

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
            $tax_rate = lyraFindTaxRate();
            $full_tag = GETPOST('fulltag', 'alpha');
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
            $full_tag = GETPOST('fulltag', 'alpha');

            $order_id = $ref;

            // IVA (VAT tax) is applied only for Colombia.
            $tax_rate = lyraFindTaxRate();

            // Customer information is consulted.
            $societe = new Societe($db);
            $result = $societe->fetch('', GETPOST('shipToName', 'alpha'), null, null, null, null, null, null, null, null, GETPOST('email', 'alpha'));
            if ($societe->code_client) {
                $cust_status = 'PRIVATE';
                $cust_id = $societe->code_client;
            } elseif ($societe->code_fournisseur) {
                $cust_status = 'COMPANY';
                $cust_id = $societe->code_fournisseur;
            }
        } else {
            print "<h1>Error: Object with ID#$ref was not found in database.</h1>";
            exit;
        }
    }

    return array('order_id' => $order_id, 'tax_rate' => $tax_rate, 'cust_id' => $cust_id, 'cust_status' => $cust_status, 'full_tag' => $full_tag);
}

/**
 * Return Tax rate configured on Dolibarr for Colombia.
 *
 * @return  $tax_rate Tax rate configured in Dolibarr
 */
function lyraFindTaxRate()
{
    global $conf, $db;
    if ($conf->global->MAIN_INFO_SOCIETE_COUNTRY == '70:CO:Colombia') {
        $sql='SELECT taux as vat_rate';
        $sql.=' FROM ' . MAIN_DB_PREFIX . 'c_tva as t, ' . MAIN_DB_PREFIX . 'c_country as c';
        $sql.=" WHERE t.active=1 AND t.fk_pays = c.rowid AND c.code='CO' AND t.taux <> 0";
        $sql.=' ORDER BY t.taux ASC';
        $resql=$db->query($sql);
        if ($resql) {
            $num = $db->num_rows($resql);
            if ($num) {
                for ($i = 0; $i < $num; $i++) {
                    $obj = $db->fetch_object($resql);
                    $vat_rates[$i] = $obj->vat_rate;
                }
            }
        }

        $tax_rate = $vat_rates[0];
    }

    return $tax_rate;
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
    $special_tokens = array('da', 'de', 'del', 'la', 'las', 'los', 'mac', 'mc', 'van', 'von', 'y', 'i', 'san', 'santa');

    $prev = '';
    foreach($tokens as $token) {
        $_token = strtolower($token);
        if (in_array($_token, $special_tokens)) {
            $prev .= "$token ";
        } else {
            $names[] = $prev . $token;
            $prev = '';
        }
    }

    $num_nombres = count($names);
    $nombres = '';
    $apellidos = '';
    switch ($num_nombres) {
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

    $appli = ! empty($conf->global->MAIN_APPLICATION_TITLE) ? $appli = $conf->global->MAIN_APPLICATION_TITLE : 'Dolibarr';

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

    if (! empty($conf->global->LYRA_CSS_URL)) {
        print '<link rel="stylesheet" type="text/css" href="' . $conf->global->LYRA_CSS_URL . '?lang=' . $langs->defaultlang . '">' . "\n";
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
        $ext='.js';
        print '<!-- Includes JS for JQuery -->' . "\n";
        print '<script type="text/javascript" src="' . DOL_URL_ROOT . '/includes/jquery/js/jquery.min' . $ext . '"></script>' . "\n";
    }

    $favicon = DOL_URL_ROOT . '/theme/dolibarr_logo_256x256.png';
    if (! empty($mysoc->logo_squarred_mini)) {
        $favicon = DOL_URL_ROOT . '/viewimage.php?cache=1&modulepart=mycompany&file=' . urlencode('logos/thumbs/' . $mysoc->logo_squarred_mini);
    }

    if (! empty($conf->global->MAIN_FAVICON_URL)) {
        $favicon = $conf->global->MAIN_FAVICON_URL;
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
    global $conf;

    return ($conf->global->LYRA_CTX_MODE === 'TEST') ? $conf->global->LYRA_REST_API_KEY_TEST : $conf->global->LYRA_REST_API_KEY_PROD;
}

/**
 * Get REST API public key.
 *
 * @return   string
 */
function getLyraPublicKey()
{
    global $conf;

    return ($conf->global->LYRA_CTX_MODE === 'TEST') ? $conf->global->LYRA_REST_API_PUBLIC_KEY_TEST : $conf->global->LYRA_REST_API_PUBLIC_KEY_PROD;
}


/**
 * Get REST API HMAC-SHA-256 key.
 *
 * @return   string
 */
function getLyraReturnKey()
{
    global $conf;

    return ($conf->global->LYRA_CTX_MODE === 'TEST') ?
        $conf->global->LYRA_REST_API_HMAC_256_KEY_TEST : $conf->global->LYRA_REST_API_HMAC_256_KEY_PROD;
}

/**
 * Print the specified message in dolibarr_lyra.log file.
 *
 * @param   string    $message    Message to be printed in log file
 * @param   string    $level      Level at which the message will be printed
 * @return  void
 */
function lyra_syslog($message, $ref = '', $level = LOG_INFO)
{
    global $conf;
    $file = basename($_SERVER['PHP_SELF']);
    $final_log =  '[' . $file . '][Ref=' . $ref . '][Mode=' . $conf->global->LYRA_CTX_MODE . '] ' . $message;
    dol_syslog($final_log, $level, '', '_lyra');
}
