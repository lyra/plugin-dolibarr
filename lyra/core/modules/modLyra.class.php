<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra Collect plugin for Dolibarr. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   http://www.gnu.org/licenses/gpl.html GNU General Public License (GPL v3)
 */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for lyra module.
 */
class modLyra extends DolibarrModules
{
    private static $MODULE_NUMBER_DOLIBARR = 230059;

    private static $GATEWAY_CODE = 'Lyra';
    private static $GATEWAY_NAME = 'Lyra Collect';
    private static $BACKOFFICE_NAME = 'Lyra Expert';
    private static $GATEWAY_URL = 'https://secure.lyra.com/vads-payment/';
    private static $SITE_ID = '12345678';
    private static $KEY_TEST = '1111111111111111';
    private static $KEY_PROD = '2222222222222222';
    private static $CTX_MODE = 'TEST';
    private static $SIGN_ALGO = 'SHA-256';
    private static $LANGUAGE = 'en';
    private static $SUPPORT_EMAIL = 'support-ecommerce@lyra-collect.com';

    private static $REST_URL = 'https://api.lyra.com/api-payment/';
    private static $STATIC_URL = 'https://api.lyra.com/static/';

    private static $CMS_IDENTIFIER = 'Dolibarr_11.x-12.x';
    private static $PLUGIN_VERSION = '1.0.1';
    private static $GATEWAY_VERSION = 'V2';

    private static $FORM_TYPE = 'REDIRECT';
    private static $RETURN_MODE = 'GET';

    private static $REDIRECT_SUCCESS_TIMEOUT = '5';
    private static $REDIRECT_ERROR_TIMEOUT = '5';

    private static $VALIDATION_MODE = '0';

    private static $PROCESS_URL = '/lyra/public/process.php';

    /**
     * Constructor. Define names, constants, directories, boxes, permissions.
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $langs, $conf;
        $this->db = $db;

        // Load translation.
        $langs->load('lyra@lyra');

        // Id for module (must be unique).
        $this->numero = self::$MODULE_NUMBER_DOLIBARR;
        $this->rights_class = 'lyra';
        $this->family = "interface";
        $this->module_position = '50';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = $langs->trans('LYRA_MODULE_DESC');
        $this->descriptionlong = '';
        $this->editor_name = 'Lyra Network';
        $this->editor_url = 'https://www.lyra.com';
        $this->version = self::$PLUGIN_VERSION;
        $this->const_name = 'MAIN_MODULE_' . strtoupper($this->name);
        $this->picto = 'lyra@lyra';

        // Define some features supported by module.
        $this->module_parts = array(
            'triggers' => 0,
            'login' => 0,
            'substitutions' => 0,
            'menus' => 0,
            'tpl' => 0,
            'barcode' => 0,
            'models' => 0,
            'theme' => 0,
            'css' => array(),
            'js' => array(),
            'hooks' => array( // Hooks context managed by module.
                'newpayment',
                'paymentlib'
            ),
            'moduleforexternal' => 0
        );

        // Data directories to create when module is enabled.
        $this->dirs = array("/lyra/temp");
        $this->config_page_url = array("lyra.php@lyra");

        $this->hidden = false;

        $this->depends = array();
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array("lyra@lyra");
        $this->phpmin = array(5, 5); // Minimum version of PHP required by module.
        $this->need_dolibarr_version = array(11, -3);
        $this->warnings_activation = array();
        $this->warnings_activation_ext = array();

        // Constants.
        $this->const = array(
            1 => array('LYRA_RETURN_MODE', 'chaine', self::$RETURN_MODE, '', 1),
            2 => array('LYRA_REDIRECT_SUCCESS_TIMEOUT', 'chaine', self::$REDIRECT_SUCCESS_TIMEOUT, '', 1),
            3 => array('LYRA_REDIRECT_SUCCESS_MESSAGE', 'chaine', $langs->trans('LYRA_REDIRECT_MESSAGE_TEXT'), '', 1),
            4 => array('LYRA_REDIRECT_ERROR_TIMEOUT', 'chaine', self::$REDIRECT_ERROR_TIMEOUT, '', 1),
            5 => array('LYRA_REDIRECT_ERROR_MESSAGE', 'chaine', $langs->trans('LYRA_REDIRECT_MESSAGE_TEXT'), '', 1),
            6 => array('LYRA_VALIDATION_MODE', 'chaine', self::$VALIDATION_MODE, '', 1),
            7 => array('LYRA_LANGUAGE', 'chaine', self::$LANGUAGE, '', 1),
            8 => array('LYRA_URL_CHECK', 'chaine', dol_buildpath(self::$PROCESS_URL, 2), '', 1),
            9 => array('LYRA_URL_RETURN', 'chaine', dol_buildpath(self::$PROCESS_URL, 2), '', 1),
            10 => array('LYRA_CTX_MODE', 'chaine', self::$CTX_MODE, '', 1),
            12 => array('LYRA_REST_API_URL_CHECK', 'chaine', dol_buildpath(self::$PROCESS_URL, 2), '', 1),
            13 => array('LYRA_GATEWAY_URL', 'chaine', self::$GATEWAY_URL, '', 1),
            14 => array('LYRA_REST_API_URL', 'chaine', self::$REST_URL, '', 1),
            15 => array('LYRA_REST_API_CLIENT_STATIC_URL', 'chaine', self::$STATIC_URL, '', 1),
            16 => array('LYRA_SITE_ID', 'chaine', self::$SITE_ID, '', 1),
            17 => array('LYRA_KEY_TEST', 'chaine', self::$KEY_TEST, '', 1),
            18 => array('LYRA_KEY_PROD', 'chaine', self::$KEY_PROD, '', 1),
            19 => array('LYRA_CARD_INFO_MODE', 'chaine', self::$FORM_TYPE, '', 1),
            20 => array('LYRA_DEVELOPPED_BY', 'chaine', "Lyra Network (https://www.lyra.com/)", '', 1),
            21 => array('LYRA_CONTACT_EMAIL', 'chaine', self::$SUPPORT_EMAIL, '', 1),
            22 => array('LYRA_MODULE_VERSION', 'chaine', self::$PLUGIN_VERSION, '', 1),
            23 => array('LYRA_GATEWAY_VERSION', 'chaine', self::$GATEWAY_VERSION, '', 1)
        );

        if (! isset($conf->lyra) || ! isset($conf->lyra->enabled)) {
            $conf->lyra = new stdClass();
            $conf->lyra->enabled = 0;
        }

        // Array to add new pages in new tabs.
        $this->tabs = array();

        // Dictionaries.
        $this->dictionaries = array();

        // Boxes/Widgets: list of php file(s) stored in lyra/core/boxes that contains a class to show a widget.
        $this->boxes = array();

        // Cronjobs.
        $this->cronjobs = array();

        // Permissions provided by this module.
        $this->rights = array();
        $r = 0;
        // Declare new permissions.
        $this->rights[$r][0] = $this->numero + $r; // Permission id (must not be already used).
        $this->rights[$r][1] = 'Read objects of lyra'; // Permission label.
        $this->rights[$r][4] = 'myobject';
        $this->rights[$r][5] = 'read';
        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Create/Update objects of lyra';
        $this->rights[$r][4] = 'myobject';
        $this->rights[$r][5] = 'write';
        $r++;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Delete objects of lyra';
        $this->rights[$r][4] = 'myobject';
        $this->rights[$r][5] = 'delete';
        $r++;

        // Main menu entries.
        $this->menu = array();
        $r = 0;
        $r = 1;
        $r = 1;
    }

    /**
     *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
     *  It also creates data directories.
     *
     *  @param      string  $options    Options when enabling module ('', 'noboxes')
     *  @return     int                 1 if OK, 0 if KO
     */
    public function init($options = '')
    {
        $result = $this->_load_tables('/lyra/sql/');
        // Do not activate module if error 'not allowed' returned when loading module SQL queries.
        if ($result < 0) {
            return -1;
        }

        // Permissions.
        $this->remove($options);

        return $this->_init(array(), $options);
    }

    /**
     *  Remove from database constants, boxes and permissions from Dolibarr database.
     *  Data directories are not deleted.
     *
     *  @param    string    $options    Options when enabling module ('', 'noboxes')
     *  @return   int                   1 if OK, 0 if KO
     */
    public function remove($options = '')
    {
        return $this->_remove(array(), $options);
    }

    /**
     *  Return defaut values.
     *
     *  @param    string    $name    Static variable name
     *  @return   string
     */
    public static function getDefault($name)
    {
        if (! is_string($name) || ! isset(self::$$name)) {
            return '';
        }

        return self::$$name;
    }

    public static function convertRestResult($answer)
    {
        if (! is_array($answer) || empty($answer)) {
            return array();
        }

        $transactions = self::getProperty($answer, 'transactions');

        if (! is_array($transactions) || empty($transactions)) {
            return array();
        }

        $transaction = $transactions[0];

        $response = array();

        $response['vads_result'] = self::getProperty($transaction, 'errorCode') ? self::getProperty($transaction, 'errorCode') : '00';
        $response['vads_extra_result'] = self::getProperty($transaction, 'detailedErrorCode');

        $response['vads_trans_status'] = self::getProperty($transaction, 'detailedStatus');
        $response['vads_trans_uuid'] = self::getProperty($transaction, 'uuid');
        $response['vads_operation_type'] = self::getProperty($transaction, 'operationType');
        $response['vads_effective_creation_date'] = self::getProperty($transaction, 'creationDate');
        $response['vads_payment_config'] = 'SINGLE'; // Only single payments are possible via REST API.

        if (($customer = self::getProperty($answer, 'customer')) && ($billingDetails = self::getProperty($customer, 'billingDetails'))) {
            $response['vads_language'] = self::getProperty($billingDetails, 'language');
        }

        $response['vads_amount'] = self::getProperty($transaction, 'amount');
        $response['vads_currency'] = LyraApi::getCurrencyNumCode(self::getProperty($transaction, 'currency')) ;

        if (($metadata = self::getProperty($transaction, 'metadata')) && is_array($metadata)) {
            foreach ($metadata as $key => $value) {
                $response['vads_ext_info_' . $key] = $value;
            }
        }

        if (self::getProperty($transaction, 'paymentMethodToken')) {
            $response['vads_identifier'] = self::getProperty($transaction, 'paymentMethodToken');
            $response['vads_identifier_status'] = 'CREATED';
        }

        if ($orderDetails = self::getProperty($answer, 'orderDetails')) {
            $response['vads_order_id'] = self::getProperty($orderDetails, 'orderId');
        }

        if ($transactionDetails = self::getProperty($transaction, 'transactionDetails')) {
            $response['vads_sequence_number'] = self::getProperty($transactionDetails, 'sequenceNumber');

            // Workarround to adapt to REST API behavior.
            $effectiveAmount = self::getProperty($transactionDetails, 'effectiveAmount');
            $effectiveCurrency = LyraApi::getCurrencyNumCode(self::getProperty($transactionDetails, 'effectiveCurrency'));

            if ($effectiveAmount && $effectiveCurrency) {
                $response['vads_effective_amount'] = $response['vads_amount'];
                $response['vads_effective_currency'] = $response['vads_currency'];
                $response['vads_amount'] = $effectiveAmount;
                $response['vads_currency'] = $effectiveCurrency;
            }

            $response['vads_warranty_result'] = self::getProperty($transactionDetails, 'liabilityShift');

            if ($cardDetails = self::getProperty($transactionDetails, 'cardDetails')) {
                $response['vads_trans_id'] = self::getProperty($cardDetails, 'legacyTransId'); // Deprecated.
                $response['vads_presentation_date'] = self::getProperty($cardDetails, 'expectedCaptureDate');

                $response['vads_card_brand'] = self::getProperty($cardDetails, 'effectiveBrand');
                $response['vads_card_number'] = self::getProperty($cardDetails, 'pan');
                $response['vads_expiry_month'] = self::getProperty($cardDetails, 'expiryMonth');
                $response['vads_expiry_year'] = self::getProperty($cardDetails, 'expiryYear');

                if ($authorizationResponse = self::getProperty($cardDetails, 'authorizationResponse')) {
                    $response['vads_auth_result'] = self::getProperty($authorizationResponse, 'authorizationResult');
                }

                if (($threeDSResponse = self::getProperty($cardDetails, 'threeDSResponse'))
                    && ($authenticationResultData = self::getProperty($threeDSResponse, 'authenticationResultData'))) {
                        $response['vads_threeds_cavv'] = self::getProperty($authenticationResultData, 'cavv');
                        $response['vads_threeds_status'] = self::getProperty($authenticationResultData, 'status');
                    }
            }

            if ($fraudManagement = self::getProperty($transactionDetails, 'fraudManagement')) {
                if ($riskControl = self::getProperty($fraudManagement, 'riskControl')) {
                    $response['vads_risk_control'] = '';

                    foreach ($riskControl as $value) {
                        $response['vads_risk_control'] .= "{$value['name']}={$value['result']};";
                    }
                }

                if ($riskAssessments = self::getProperty($fraudManagement, 'riskAssessments')) {
                    $response['vads_risk_assessment_result'] = self::getProperty($riskAssessments, 'results');
                }
            }
        }

        return $response;
    }

    private static function getProperty($array, $key)
    {
        if (isset($array[$key])) {
            return $array[$key];
        }

        return null;
    }

    public static function checkHash($data, $key)
    {
        $supported_sign_algos = array('sha256_hmac');

        // Check if the hash algorithm is supported.
        if (! in_array($data['kr-hash-algorithm'], $supported_sign_algos)) {
            return false;
        }

        // On some servers, / can be escaped.
        $kr_answer = str_replace('\/', '/', $data['kr-answer']);

        $hash = hash_hmac('sha256', $kr_answer, $key);

        // Return true if calculated hash and sent hash are the same.
        return ($hash === $data['kr-hash']);
    }
}