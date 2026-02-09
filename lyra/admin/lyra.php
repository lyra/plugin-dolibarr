<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra Collect plugin for Dolibarr. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   http://www.gnu.org/licenses/gpl.html GNU General Public License (GPL v3)
 */

// Load Dolibarr environment.
$res = 0;

if (! $res && file_exists(__DIR__ . '/../../../main.inc.php')) {
    $res = include __DIR__ . '/../../../main.inc.php';
}

if (! $res && file_exists('../../main.inc.php')) {
    $res = @include '../../main.inc.php';
}

// Try main.inc.php into custom directory.
if (! $res && file_exists(__DIR__ . '/../../../dolibarr/htdocs/main.inc.php')) {
    $res = include __DIR__ . '/../../../dolibarr/htdocs/main.inc.php';
}

if (! $res && file_exists(__DIR__ . '/../../dolibarr/htdocs/main.inc.php')) {
    $res = include __DIR__ . '/../../dolibarr/htdocs/main.inc.php';
}

if (! $res) {
    die('Include of main.inc.php failed');
}

global $langs, $user;

// Libraries.
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once '../lib/lyra.lib.php';
require_once('../core/modules/modLyra.class.php');
require_once '../class/sdk-autoload.php';

$servicename = 'Lyra';

// Load translation files required by the page.
$langs->loadLangs(array('admin', 'other', 'lyra@lyra'));

// Access control.
if (empty($user->admin)) {
    accessforbidden();
}

if (! isModEnabled('lyra')) {
    accessforbidden();
}

if (! lyraCanUseCurrency()) {
    print info_admin($langs->trans('LYRA_UNSUPPORTED_CURRENCY_MESSAGE'), 0, 0, 'error');
}

// Parameters.
$action = GETPOST('action', 'alpha');
$backtopage = GETPOST('backtopage', 'alpha');

$contextMode = array(
    'TEST'       => $langs->trans('LYRA_MODE_TEST'),
    'PRODUCTION' => $langs->trans('LYRA_MODE_PRODUCTION')
);

$availableLanguages = array();
foreach (\Lyranetwork\Lyra\Sdk\Form\Api::getSupportedLanguages() as $code => $label) {
    $availableLanguages[$code] = $langs->trans(strtoupper($label));
}

$validationMode = array(
    '' => $langs->trans('LYRA_VALIDATION_MODE_BACK_OFFICE_CONF'),
    '0' => $langs->trans('LYRA_VALIDATION_MODE_AUTOMATIC'),
    '1' => $langs->trans('LYRA_VALIDATION_MODE_MANUAL')
);

$returnMode = array(
    'GET'  => $langs->trans('LYRA_RETURN_MODE_GET'),
    'POST' => $langs->trans('LYRA_RETURN_MODE_POST')
);

$formType = array(
    modLyra::MODE_FORM => $langs->trans('LYRA_CARD_INFO_MODE_FORM'),
    modLyra::MODE_EMBEDDED => $langs->trans('LYRA_CARD_INFO_MODE_EMBEDDED'),
    modLyra::MODE_EMBEDDED_EXT_WITH_LOGOS  => $langs->trans('LYRA_CARD_INFO_MODE_EMBEDDED_EXT_WITH_LOGOS'),
    modLyra::MODE_EMBEDDED_EXT_WITHOUT_LOGOS => $langs->trans('LYRA_CARD_INFO_MODE_EMBEDDED_EXT_WITHOUT_LOGOS')
);

$embeddedFormTheme = array(
    'neon'  => 'Neon',
    'classic' => 'Classic'
);

$parameters = array(
    'LYRA_MODULE_INFORMATION' => array(
        'LYRA_DEVELOPPED_BY'   => array('type' => 'link', 'css' => 'minwidth500', 'enabled' => 0, 'constant' => 'https://www.lyra.com/', 'linktext' => 'Lyra Network'),
        'LYRA_CONTACT_EMAIL'   => array('type' => 'link', 'css' => 'minwidth500', 'enabled' => 0, 'constant' => modLyra::SUPPORT_EMAIL, 'linktext' => $langs->trans('LYRA_CLICK_HERE')),
        'LYRA_MODULE_VERSION'  => array('type' => 'constant', 'css' => 'minwidth500', 'enabled' => 0, 'constant' => modLyra::PLUGIN_VERSION),
        'LYRA_MODULE_DOC_LINK' => array('type' => 'link', 'css' => 'minwidth500', 'enabled' => 0, 'multilink' => Lyranetwork\Lyra\Sdk\Form\Api::getOnlineDocUri())
    ),

    'LYRA_PAYMENT_GATEWAY_ACCESS' => array(
        'LYRA_SITE_ID'     => array('type' => 'number', 'css' => 'minwidth500', 'enabled' => 1, 'regex' => modLyra::SITE_ID_REGEX, 'info' => $langs->trans('LYRA_SITE_ID_DESC')),
        'LYRA_KEY_TEST'    => array('type' => 'password', 'class' => 'lyra_redirection', 'css' => 'minwidth500', 'enabled' => 1, 'regex' => modLyra::KEY_REGEX, 'info' => $langs->trans('LYRA_KEY_TEST_DESC')),
        'LYRA_KEY_PROD'    => array('type' => 'password', 'class' => 'lyra_redirection', 'css' => 'minwidth500', 'enabled' => 1, 'regex' => modLyra::KEY_REGEX, 'info' => $langs->trans('LYRA_KEY_PROD_DESC')),
        'LYRA_CTX_MODE'    => array('type' => 'selectarray', 'data' => $contextMode, 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_CTX_MODE_DESC')),
        'LYRA_URL_CHECK'   => array('type' => 'constant', 'class' => 'lyra_redirection', 'css' => 'minwidth500', 'enabled' => 0, 'constant' => dol_buildpath(modLyra::PROCESS_URL, 2), 'note' => $langs->trans('LYRA_URL_CHECK_DESC'))
    ),

    'LYRA_REST_API' => array(
        'LYRA_REST_API_KEY_TEST'          => array('type' => 'password', 'class' => 'lyra_embedded', 'css' => 'minwidth500', 'enabled' => 1, 'regex' => modLyra::TEST_PRIVATE_KEY_REGEX, 'info' => $langs->trans('LYRA_REST_API_DESC')),
        'LYRA_REST_API_KEY_PROD'          => array('type' => 'password', 'class' => 'lyra_embedded', 'css' => 'minwidth500', 'enabled' => 1, 'regex' => modLyra::PROD_PRIVATE_KEY_REGEX, 'info' => $langs->trans('LYRA_REST_API_DESC')),
        'LYRA_REST_API_PUBLIC_KEY_TEST'   => array('type' => 'input', 'class' => 'lyra_embedded', 'css' => 'minwidth500', 'enabled' => 1, 'regex' => modLyra::TEST_PUBLIC_KEY_REGEX, 'info' => $langs->trans('LYRA_REST_API_DESC')),
        'LYRA_REST_API_PUBLIC_KEY_PROD'   => array('type' => 'input', 'class' => 'lyra_embedded', 'css' => 'minwidth500', 'enabled' => 1, 'regex' => modLyra::PROD_PUBLIC_KEY_REGEX, 'info' => $langs->trans('LYRA_REST_API_DESC')),
        'LYRA_REST_API_HMAC_256_KEY_TEST' => array('type' => 'password', 'class' => 'lyra_embedded', 'css' => 'minwidth500', 'enabled' => 1, 'regex' => modLyra::HMAC_KEY_REGEX, 'info' => $langs->trans('LYRA_REST_API_DESC')),
        'LYRA_REST_API_HMAC_256_KEY_PROD' => array('type' => 'password', 'class' => 'lyra_embedded', 'css' => 'minwidth500', 'enabled' => 1, 'regex' => modLyra::HMAC_KEY_REGEX, 'info' => $langs->trans('LYRA_REST_API_DESC')),
        'LYRA_REST_API_URL_CHECK'         => array('type' => 'constant', 'class' => 'lyra_embedded', 'css' => 'minwidth500', 'enabled' => 0, 'constant' => dol_buildpath(modLyra::PROCESS_URL, 2),'note' => $langs->trans('LYRA_URL_CHECK_DESC'))
    ),

    'LYRA_PAYMENT_PAGE' => array(
        'LYRA_LANGUAGE'            => array('type' => 'selectarray', 'data' => $availableLanguages, 'class' => 'lyra_redirection', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_LANGUAGE_DESC')),
        'LYRA_AVAILABLE_LANGUAGES' => array('type' => 'selectmultiarray', 'data' => $availableLanguages, 'class' => 'lyra_redirection', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_AVAILABLE_LANGUAGES_DESC')),
        'LYRA_CAPTURE_DELAY'       => array('type' => 'number', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_CAPTURE_DELAY_DESC')),
        'LYRA_VALIDATION_MODE'     => array('type' => 'selectarray', 'data' => $validationMode, 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_VALIDATION_MODE_DESC'))
    ),

    'LYRA_RETURN_TO_SHOP' => array(
        'LYRA_REDIRECT_ENABLED'         => array('type' => 'dolibarr_toggle', 'class' => 'lyra_redirection', 'css' => 'minwidth500', 'enabled' => 0, 'info' => $langs->trans('LYRA_REDIRECT_ENABLED_DESC')),
        'LYRA_REDIRECT_SUCCESS_TIMEOUT' => array('type' => 'number', 'class' => 'redirect_field lyra_redirection', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_REDIRECT_SUCCESS_TIMEOUT_DESC')),
        'LYRA_REDIRECT_SUCCESS_MESSAGE' => array('type' => 'input', 'class' => 'redirect_field lyra_redirection', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_REDIRECT_SUCCESS_MESSAGE_DESC')),
        'LYRA_REDIRECT_ERROR_TIMEOUT'   => array('type' => 'number', 'class' => 'redirect_field lyra_redirection', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_REDIRECT_ERROR_TIMEOUT_DESC')),
        'LYRA_REDIRECT_ERROR_MESSAGE'   => array('type' => 'input', 'class' => 'redirect_field lyra_redirection', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_REDIRECT_ERROR_MESSAGE_DESC')),
        'LYRA_RETURN_MODE'              => array('type' => 'selectarray', 'class' => 'lyra_redirection', 'data' => $returnMode, 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_RETURN_MODE_DESC'))
    ),

    'LYRA_ADVANCED_OPTIONS' => array(
        'LYRA_CARD_INFO_MODE' => array('type' => 'selectarray', 'data' => $formType, 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_CARD_INFO_MODE_DESC')),
        'LYRA_POPIN_MODE'     => array('type' => 'dolibarr_toggle', 'class' => 'lyra_embedded', 'css' => 'minwidth500', 'enabled' => 0, 'info' => $langs->trans('LYRA_POPIN_MODE_DESC')),
        'LYRA_EMBEDDED_THEME' => array('type' => 'selectarray', 'class' => 'lyra_embedded', 'data' => $embeddedFormTheme, 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_EMBEDDED_THEME_DESC')),
        'LYRA_COMPACT_MODE'   => array('type' => 'dolibarr_toggle', 'class' => 'lyra_embedded', 'css' => 'minwidth500', 'enabled' => 0, 'info' => $langs->trans('LYRA_COMPACT_MODE_DESC')),
        'LYRA_RETRY_NUMBER'   => array('type' => 'number', 'class' => 'lyra_embedded', 'css' => 'minwidth500', 'attributes' => 'max="2"', 'enabled' => 1, 'info' => $langs->trans('LYRA_RETRY_NUMBER_DESC'))
    ),

    'LYRA_DOLIBARR_SETTINGS' => array(
        'LYRA_DOLIBARR_BANK_ACCOUNT' => array('type' => 'selectbanks', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_DOLIBARR_BANK_ACCOUNT_DESC'))
    )
);

$mandatoryRedirectFields = [
    'LYRA_KEY_TEST',
    'LYRA_KEY_PROD'
];

$mandatoryRestFields = [
    'LYRA_REST_API_KEY_TEST',
    'LYRA_REST_API_KEY_PROD',
    'LYRA_REST_API_PUBLIC_KEY_TEST',
    'LYRA_REST_API_PUBLIC_KEY_PROD',
    'LYRA_REST_API_HMAC_256_KEY_TEST',
    'LYRA_REST_API_HMAC_256_KEY_PROD'
];

$passwordFields = [
    'LYRA_KEY_TEST',
    'LYRA_KEY_PROD',
    'LYRA_REST_API_KEY_TEST',
    'LYRA_REST_API_KEY_PROD',
    'LYRA_REST_API_HMAC_256_KEY_TEST',
    'LYRA_REST_API_HMAC_256_KEY_PROD'
];

// Module responsable for updating the value of the parameters.
include DOL_DOCUMENT_ROOT . '/core/actions_setmoduleoptions.inc.php';

// Actions.
if (($action == 'updateValues') && $user->admin) {
    $db->begin();

    // Values of constants are set up to Database.
    foreach ($parameters as $section => $items) {
        foreach($items as $key => $val) {
            if ($val['enabled'] === 0) {
                continue;
            }

            $type = in_array($key, $passwordFields) ? 'password' : 'alpha';
            $value = GETPOST($key, $type);

            if (is_array($value)) {
                $value = implode(';', $value);
            }

            // Check if site id parameter is set.
            if (($key === 'LYRA_SITE_ID') && ! checkRegex($val['regex'], $value)) {
                $msg = $langs->transnoentities("LYRA_INVALID_VALUE", $langs->trans($key));
                setEventMessages($msg, null, 'errors');

                continue;
            }

            $modeSuffix = GETPOST('LYRA_CTX_MODE' , 'alpha') == 'TEST' ? 'TEST' : 'PROD';

            // Check if embedded payment parameters are correctly set.
            if (in_array($key, $mandatoryRestFields) && lyraEmbeddedPayment(GETPOST('LYRA_CARD_INFO_MODE', 'alpha'))) {
                $regex = $val['regex'];
                if ($key == 'LYRA_REST_API_PUBLIC_KEY_' . $modeSuffix) {
                    $regex = '^' . GETPOST('LYRA_SITE_ID' , 'alpha') . $regex;
                }

                if (strpos($key, $modeSuffix) && ! checkRegex($regex, $value)) {
                    $msg = $langs->transnoentities("LYRA_INVALID_VALUE", $langs->trans($key));
                    setEventMessages($msg, null, 'errors');

                    continue;
                }
            } elseif (in_array($key, $mandatoryRedirectFields) && ! lyraEmbeddedPayment(GETPOST('LYRA_CARD_INFO_MODE', 'alpha'))) {
                if (strpos($key, $modeSuffix) && ! checkRegex($val['regex'], $value)) {
                    $msg = $langs->transnoentities("LYRA_INVALID_VALUE", $langs->trans($key));
                    setEventMessages($msg, null, 'errors');

                    continue;
                }
            }

            $result = dolibarr_set_const($db, $key, $value, 'chaine', 0, '', $conf->entity);

            if (! $result > 0) {
                $error++;
            }
        }
    }

    // Commit or rollback.
    if (! $error) {
        $db->commit();
        setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
    } else {
        $db->rollback();
        dol_print_error($db);
    }
}

// View.
llxHeader('', $langs->trans('LYRA_MODULE_CONFIGURATION'));

// Subheader.
$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans('BackToModuleList') . '</a>';
print load_fiche_titre($langs->trans('LYRA_MODULE_CONFIGURATION'), $linkback, 'object_lyra@lyra');

// Configuration header.
print dol_get_fiche_head([], 'settings', '', -1, 'lyra@lyra');

// Setup page goes here.
print '<span class="opacitymedium">' . $langs->trans('LYRA_MODULE_DESC') . '</span><br>';

print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="updateValues">';

foreach ($parameters as $section => $items) {
    // Print header section.
    print '<div class="div-table-responsive-no-min" style="margin-bottom: 15px;" id="' . $section . '">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre"><td class="titlefield" ><strong>' . $langs->trans($section) . '</strong></td><td>' . $langs->trans('Value') . '</td><td></td></tr>';

    // Print fields (parameters).
    foreach ($items as $key => $value) {
        // Print field name.
        print '<tr class="oddeven ' . (empty($value['class']) ? '' : $value['class']) . '"><td width="400">';
        print '<span class="fieldrequired">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . $langs->trans($key) . '</td><td>';

        // Print field value.
        switch ($value['type']) {
            case 'selectarray':
                print $form->selectarray($key, $value['data'], getDolGlobalString($key), 0, 0, 0, '', 0, 0, 0, '', (empty($value['class']) ? '' : $value['class']));

                break;

            case 'selectmultiarray':
                $selectedAvailableLanguages = empty(getDolGlobalString($key)) ? [] : explode(';', getDolGlobalString($key));
                print $form->multiselectarray($key, $value['data'], $selectedAvailableLanguages, 0, 0, '', 1, '45%');

                break;

            case 'selectbanks':
                $form->select_comptes(getDolGlobalString($key) ?? '', $key, 0, '', 1);

                break;

            case 'dolibarr_toggle':
                if ($conf->use_javascript_ajax) {
                    print ajax_constantonoff($key);
                } else {
                    $arrval = array('0' => $langs->trans('NO'), '1' => $langs->trans('YES'));
                    print $form->selectarray($key, $arrval, getDolGlobalString($key));
                }

                break;

            case 'link':
                break;

            case 'constant':
                if (! empty($value['constant'])) {
                    print '
                    <div>
                        <span class="flat ' . (empty($value['css']) ? 'minwidth200' : $value['css']) .'"> '.  $value['constant'] . '</span>
                    </div>';
                }

                break;

            case 'input':
            case 'number':
            default:
                print '<input name="' . $key . '"' . ($value['type'] != 'input' ? ' type="' . $value['type'] . '"' : '') . ' class="flat ' .
                    (empty($value['css']) ? 'minwidth200' : $value['css']) . ' ' . (empty($value['class']) ? '' : $value['class']) . '"
                    value="' . getDolGlobalString($key) . '" ' . ($value['enabled'] == '0' ? 'readonly style="background-color:#F0F2F5"' : '') .
                    (isset($value['attributes']) ? ' ' . $value['attributes'] : '') . '>';

                break;
        }

        // Show extra information for the field.
        if (! empty($value['note'])) {
            print '
            <div class="warning ">
                <span style="font-size: 18px; color: Dodgerblue;">
                    <i class="fas fa-exclamation-circle"></i>
                </span>
                <span>' . $langs->trans($value['note']) . '</span>
            </div>';
        }

        // Show field description in an icon tooltip.
        if (! empty($value['info'])) {
            print '</td><td>
            <span class="" style="font-size: 18px; color: Dodgerblue;" title = "' . $value['info'] . '">
                <i class="fas fa-question-circle"></i>
            </span>';
        }

        if (! empty($value['linktext'])) {
            print '<div>' . \Lyranetwork\Lyra\Sdk\Form\Api::formatSupportEmails($value['constant'], $value['linktext']) . '</div>';
            print '</td><td>';
        }

        if (! empty($value['multilink'])) {
            $languages = array(
                'fr' => 'Français',
                'en' => 'English',
                'es' => 'Español',
                'pt' => 'Português'
                // Complete when other languages are managed.
            );

            print '<div>';
            foreach ($value['multilink'] as $lang => $docUri) {
                print '<a style="margin-left: 10px; text-decoration: none; text-transform: uppercase;" href="' . $docUri . 'dolibarr22/sitemap.html" target="_blank">' . $languages[$lang] . '</a>';
            }

            print '</div>';
            print '</td><td>';
        }
    }

    print '</table>
         </div>
        ';
}

print '<tr class="oddeven"><td>';

print '</td></tr>';

print '<br><div class="center">';
print '<input class="button" type="submit" value="' . $langs->trans('Save') . '">';
print '</div>';

print '</form>';
print '<br>';

print '<script type="text/javascript">';
if (getDolGlobalString('LYRA_CARD_INFO_MODE') == modLyra::MODE_FORM) {
    print '$(".lyra_redirection").show();';
    print '$("#LYRA_RETURN_TO_SHOP").show();';
    print '$(".lyra_embedded").hide();';
    print '$("#LYRA_REST_API").hide();';

    if (! getDolGlobalString('LYRA_REDIRECT_ENABLED')) {
        print '$(".redirect_field").hide();';
    }
} else {
    print '$(".lyra_embedded").show();';
    print '$("#LYRA_REST_API").show();';
    print '$(".lyra_redirection").hide();';
    print '$("#LYRA_RETURN_TO_SHOP").hide();';
}

print '
// Set constant.
$(document).ready(
    function() {
        $("#set_LYRA_REDIRECT_ENABLED").click(
            function() {
                $(".redirect_field").show();
                $(".redirect_field").prop("disabled", false);
            }
        );

        // Del constant.
        $("#del_LYRA_REDIRECT_ENABLED").click(
            function() {
                $(".redirect_field").hide(); // To hide fields when toggle is disabled.
                $(".redirect_field").prop("disabled", true);
            }
        );

        $("#LYRA_CARD_INFO_MODE").change(
            function() {
                let value = $("select#LYRA_CARD_INFO_MODE option:selected").val();
                if (value === "1") {
                    $("#LYRA_RETURN_TO_SHOP").show();
                    $(".lyra_redirection").show();
                    $(".lyra_embedded").hide();
                    $("#LYRA_REST_API").hide();
                } else {
                    $(".lyra_embedded").show();
                    $("#LYRA_REST_API").show();
                    $("#LYRA_RETURN_TO_SHOP").hide();
                    $(".lyra_redirection").hide();
                }
            }
        );
    }
);
</script>
';

require_once DOL_DOCUMENT_ROOT.'/core/lib/payments.lib.php';

// Url list
print '<u>' . $langs->trans("FollowingUrlAreAvailableToMakePayments") . ':</u><br><br>';
print img_picto('', 'globe') . ' <span class="opacitymedium">' . $langs->trans("ToOfferALinkForOnlinePaymentOnFreeAmount", $servicename) . ':</span><br>';
print '<strong class="wordbreak">' . getOnlinePaymentUrl(1, 'free') . "</strong><br><br>\n";

if (isModEnabled('invoice')) {
    print '<div id="invoice"></div>';
    print img_picto('', 'globe') . ' <span class="opacitymedium">' . $langs->trans("ToOfferALinkForOnlinePaymentOnInvoice", $servicename) . ':</span><br>';
    print '<strong class="wordbreak">' . getOnlinePaymentUrl(1, 'invoice') . "</strong><br>\n";

    if (getDolGlobalString('PAYMENT_SECURITY_TOKEN') && getDolGlobalString('PAYMENT_SECURITY_TOKEN_UNIQUE')) {
        $langs->load("bills");
        print '<form action="' . $_SERVER["PHP_SELF"] . '#invoice" method="POST">';
        print '<input type="hidden" name="token" value="' . newToken() . '">';

        print $langs->trans("EnterRefToBuildUrl", $langs->transnoentitiesnoconv("Invoice")) . ': ';
        print '<input type="text class="flat" id="generate_invoice_ref" name="generate_invoice_ref" value="' . GETPOST('generate_invoice_ref', 'alpha') . '" size="10">';
        print '<input type="submit" class="none reposition button smallpaddingimp" value="' . $langs->trans("GetSecuredUrl") . '">';

        if (GETPOST('generate_invoice_ref', 'alpha')) {
            $url = getOnlinePaymentUrl(0, 'invoice', GETPOST('generate_invoice_ref', 'alpha'));
            print '<div class="urllink"><input type="text" class="wordbreak quatrevingtpercent" value="';
            print $url;
            print '"></div>' . "\n";
        }

        print '</form>';
    }

    print '<br>';
}

// Page end.
dol_fiche_end();

llxFooter();
$db->close();