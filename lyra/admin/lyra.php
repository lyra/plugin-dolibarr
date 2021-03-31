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

// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined).
if (! $res && ! empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
    $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
}

// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME.
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);

$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
    $i--;
    $j--;
}

if (! $res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) {
    $res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
}

if (! $res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) {
    $res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
}

// Try main.inc.php using relative path.
if (! $res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}

if (! $res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}

if (! $res) {
    die("Include of main fails");
}

global $langs, $user;

// Libraries.
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
require_once "../class/LyraApi.php";

// Translations.
$langs->loadLangs(array('admin', 'other', 'lyra@lyra'));

// Access control.
if (! $user->admin) {
    accessforbidden();
}

// Parameters.
$action = GETPOST('action', 'alpha');
$backtopage = GETPOST('backtopage', 'alpha');

$context_mode_vars = array(
    'TEST'       => $langs->trans('LYRA_MODE_TEST'),
    'PRODUCTION' => $langs->trans('LYRA_MODE_PRODUCTION')
);

$available_languages_lyra = array();
foreach (LyraApi::getSupportedLanguages() as $code => $label) {
    $available_languages_lyra[$code] = $langs->trans(strtoupper($label));
}

$validation_mode = array(
    '0' => $langs->trans('LYRA_VALIDATION_MODE_AUTOMATIC'),
    '1' => $langs->trans('LYRA_VALIDATION_MODE_MANUAL')
);

$return_mode = array(
    'GET'  => $langs->trans('LYRA_RETURN_MODE_GET'),
    'POST' => $langs->trans('LYRA_RETURN_MODE_POST')
);

$form_type = array(
    'REDIRECT' => $langs->trans('LYRA_CARD_INFO_MODE_REDIRECT'),
    'EMBEDDED' => $langs->trans('LYRA_CARD_INFO_MODE_EMBEDDED'),
    'POP-IN' => $langs->trans('LYRA_CARD_INFO_MODE_EMBEDDED_POP_IN')
);

$arrayofparameters = array(
    'LYRA_MODULE_INFORMATION' => array(
        'LYRA_DEVELOPPED_BY'   => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 0),
        'LYRA_CONTACT_EMAIL'   => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 0),
        'LYRA_MODULE_VERSION'  => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 0),
        'LYRA_GATEWAY_VERSION' => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 0)
    ),

    'LYRA_PAYMENT_GATEWAY_ACCESS' => array(
        'LYRA_SITE_ID'     => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_SITE_ID_DESC')),
        'LYRA_KEY_TEST'    => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_KEY_TEST_DESC')),
        'LYRA_KEY_PROD'    => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_KEY_PROD_DESC')),
        'LYRA_CTX_MODE'    => array('type' => 'selectarray', 'data' => $context_mode_vars, 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_CTX_MODE_DESC')),
        'LYRA_URL_CHECK'   => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 0, 'note' => $langs->trans('LYRA_URL_CHECK_DESC')),
        'LYRA_GATEWAY_URL' => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_GATEWAY_URL_DESC'))
    ),

    'LYRA_REST_API' => array(
        'LYRA_REST_API_KEY_TEST'          => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_REST_API_DESC')),
        'LYRA_REST_API_KEY_PROD'          => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_REST_API_DESC')),
        'LYRA_REST_API_PUBLIC_KEY_TEST'   => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_REST_API_DESC')),
        'LYRA_REST_API_PUBLIC_KEY_PROD'   => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_REST_API_DESC')),
        'LYRA_REST_API_HMAC_256_KEY_TEST' => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_REST_API_DESC')),
        'LYRA_REST_API_HMAC_256_KEY_PROD' => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_REST_API_DESC')),
        'LYRA_REST_API_URL'               => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 1),
        'LYRA_REST_API_CLIENT_STATIC_URL' => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 1),
        'LYRA_REST_API_URL_CHECK'         => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 0, 'note' => $langs->trans('LYRA_URL_CHECK_DESC'))
    ),

    'LYRA_PAYMENT_PAGE' => array(
        'LYRA_LANGUAGE'            => array('type' => 'selectarray', 'data' => $available_languages_lyra, 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_LANGUAGE_DESC')),
        'LYRA_AVAILABLE_LANGUAGES' => array('type' => 'selectmultiarray', 'data' => $available_languages_lyra, 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_AVAILABLE_LANGUAGES_DESC')),
        'LYRA_CAPTURE_DELAY'       => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_CAPTURE_DELAY_DESC')),
        'LYRA_VALIDATION_MODE'     => array('type' => 'selectarray', 'data' => $validation_mode, 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_VALIDATION_MODE_DESC'))
    ),

    'LYRA_PAGE_CUSTOMIZATION' => array(
        'LYRA_THEME_CONFIG' => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_THEME_CONFIG_DESC')),
        'LYRA_SHOP_NAME'    => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_SHOP_NAME_DESC')),
        'LYRA_SHOP_URL'     => array('type' => 'input', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_SHOP_URL_DESC'))
    ),

    'LYRA_RETURN_TO_SHOP' => array(
        'LYRA_REDIRECT_ENABLED'         => array('type' => 'dolibarr_toggle', 'css' => 'minwidth500', 'enabled' => 0, 'info' => $langs->trans('LYRA_REDIRECT_ENABLED_DESC')),
        'LYRA_REDIRECT_SUCCESS_TIMEOUT' => array('type' => 'input', 'class' => 'redirect_field', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_REDIRECT_SUCCESS_TIMEOUT_DESC')),
        'LYRA_REDIRECT_SUCCESS_MESSAGE' => array('type' => 'input', 'class' => 'redirect_field', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_REDIRECT_SUCCESS_MESSAGE_DESC')),
        'LYRA_REDIRECT_ERROR_TIMEOUT'   => array('type' => 'input', 'class' => 'redirect_field', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_REDIRECT_ERROR_TIMEOUT_DESC')),
        'LYRA_REDIRECT_ERROR_MESSAGE'   => array('type' => 'input', 'class' => 'redirect_field', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_REDIRECT_ERROR_MESSAGE_DESC')),
        'LYRA_RETURN_MODE'              => array('type' => 'selectarray', 'data' => $return_mode, 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_RETURN_MODE_DESC'))
    ),

    'LYRA_ADVANCED_OPTIONS' => array(
        'LYRA_CARD_INFO_MODE' => array('type' => 'selectarray', 'data' => $form_type, 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_CARD_INFO_MODE_DESC')) //Form type.
    ),

    'LYRA_DOLIBARR_SETTINGS' => array(
        'LYRA_DOLIBARR_BANK_ACCOUNT' => array('type' => 'selectbanks', 'css' => 'minwidth500', 'enabled' => 1, 'info' => $langs->trans('LYRA_DOLIBARR_BANK_ACCOUNT_DESC'))
    )
);

// View.
llxHeader('', $langs->trans("LYRA_MODULE_CONFIGURATION"));

// Subheader.
$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans("LYRA_MODULE_CONFIGURATION"), $linkback, 'object_lyra@lyra');

// Configuration header.
dol_fiche_head($head, 'settings', '', -1, "lyra@lyra");

// Setup page goes here.
echo '<span class="opacitymedium">' . $langs->trans("LYRA_MODULE_DESC") . '</span><br><br>';

// Actions.
if ((float) DOL_VERSION >= 6) {
    // Module responsable for updating the value of the parameters.
    include DOL_DOCUMENT_ROOT . '/core/actions_setmoduleoptions.inc.php';
}

if (($action == 'updateValues') && $user->admin) {
    $db->begin();

    // Values of constants are set up to Database.
    foreach ($arrayofparameters as $section => $item) {
        foreach($item as $key => $val) {
            if (is_array(GETPOST($key, 'alpha'))) {
                $list = implode(";", GETPOST($key, 'alpha'));
                $result = dolibarr_set_const($db, $key, $list, 'chaine', 0, '', $conf->entity);
            } else {
                if ($key == 'LYRA_REDIRECT_ENABLED') continue;
                if ((! $conf->global->LYRA_REDIRECT_ENABLED) && ($val['class'] === 'redirect_field')) continue;
                if ($val['enabled'] === 0) continue;

                $result = dolibarr_set_const($db, $key, GETPOST($key, 'alpha'), 'chaine', 0, '', $conf->entity);
            }

            if (! $result > 0) {
                $error++;
            }
        }
    }

    // Commit or rollback.
    if (! $error) {
        $db->commit();
        setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
    } else {
        $db->rollback();
        dol_print_error($db);
    }
}

print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="token" value="' . newToken() . '">';
print '<input type="hidden" name="action" value="updateValues">';
print '<table class="noborder centpercent">';
foreach ($arrayofparameters as $section => $item) {
    // Print header section.
    print '<tr class="liste_titre"><td class="titlefield" ><strong>' . $langs->trans($section) . '</strong></td><td>' . $langs->trans("Value") . '</td><td></td></tr>';

    // Print fields (parameters).
    foreach ($item as $key => $value) {

        // Print field name.
        print '<tr class="oddeven ' . (empty($value['class']) ? '' : $value['class']) . '"><td width="400">';
        print '<span class="fieldrequired">&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;' . $langs->trans($key) . '</td><td>';

        // Print field value.
        switch ($value['type']) {
            case 'dolibarr_selectcurrency':
                print $form->selectCurrency($conf->global->$key, $key, 1);
                break;
            case 'selectarray':
                print $form->selectarray($key, $value['data'], $conf->global->$key, 0, 0, 0, '', 0, 0, 0, '', $value['class']);
                break;
            case 'selectmultiarray':
                $selected_available_languages_lyra = explode(";", $conf->global->$key);
                print $form->multiselectarray($key, $value['data'], $selected_available_languages_lyra, 0, 0, '', 1, '45%');
                break;
            case 'selectbanks':
                $form->select_comptes($conf->global->$key, $key, 0, '', 1);
                break;
            case 'dolibarr_toggle':
                if ($conf->use_javascript_ajax) {
                    print ajax_constantonoff($key);
                } else {
                    $arrval = array('0' => $langs->trans("NO"), '1' => $langs->trans("YES"));
                    print $form->selectarray($key, $arrval, $conf->global->$key);
                }

                break;
            case 'input':
            case '':
                print '<input name="' . $key . '"  class="flat ' . (empty($value['css']) ? 'minwidth200' : $value['css']) . ' ' . (empty($value['class']) ? '' : $value['class']) . '" value="' . $conf->global->$key . '" ' . ($value['enabled'] == '0' ? 'readonly style = "background-color:#F0F2F5" ' : '') . '>';
                break;
        }

        // Show examples for the field.
        if (! empty($value['example'])) {
            print '<pre style="display:inline">&#09;</pre>' . $langs->trans("Example") . ': ' . $value['example'];
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
    }
}

print '</table>';

print '<tr class="oddeven"><td>';

print '</td></tr>';

print '<br><div class="center">';
print '<input class="button" type="submit" value="' . $langs->trans("Save") . '">';
print '</div>';

print '</form>';
print '<br>';

if (empty($conf->global->LYRA_REDIRECT_ENABLED)) {
    print '<script type="text/javascript">';
    print '$(".redirect_field").hide();';
    print '</script>';
}

print '<script type="text/javascript">
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
                $(".redirect_field").hide(); // TO hide fields when toggle is disabled.
                $(".redirect_field").prop("disabled", true);
            }
        );
    }
);
</script>
';

require_once DOL_DOCUMENT_ROOT . '/core/lib/payments.lib.php';
// To show test URL for payment.
print '<u>' . $langs->trans("FollowingUrlAreAvailableToMakePayments") . ':</u><br><br>';
print img_picto('', 'globe') . ' ' . $langs->trans("ToOfferALinkForOnlinePaymentOnFreeAmount", $servicename) . ':<br>';
print '<strong class="wordbreak">' . getOnlinePaymentUrl(1, 'free') . "</strong><br><br>\n";

if (! empty($conf->facture->enabled)) {
    print '<div id="invoice"></div>';
    print img_picto('', 'globe') . ' ' . $langs->trans("ToOfferALinkForOnlinePaymentOnInvoice", $servicename) . ':<br>';
    print '<strong class="wordbreak">' . getOnlinePaymentUrl(1, 'invoice') . "</strong><br>\n";
    if (! empty($conf->global->PAYMENT_SECURITY_TOKEN) && ! empty($conf->global->PAYMENT_SECURITY_TOKEN_UNIQUE)) {
        $langs->load("bills");
        print '<form action="' . $_SERVER["PHP_SELF"] . '#invoice" method="POST">';
        print $langs->trans("EnterRefToBuildUrl", $langs->transnoentitiesnoconv("Invoice")) . ': ';
        print '<input type="text class="flat" id="generate_invoice_ref" name="generate_invoice_ref" value="' . GETPOST('generate_invoice_ref', 'alpha') . '" size="10">';
        print '<input type="submit" class="none button" value="' . $langs->trans("GetSecuredUrl") . '">';
        if (GETPOST('generate_invoice_ref', 'alpha')) {
            print '<br> -> <strong class="wordbreak">';
            $url = getOnlinePaymentUrl(0, 'invoice', GETPOST('generate_invoice_ref', 'alpha'));
            print $url;
            print "</strong><br>\n";
        }

        print '</form>';
    }

    print '<br>';
}

// Page end.
dol_fiche_end();

llxFooter();
$db->close();