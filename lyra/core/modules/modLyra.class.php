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
    const MODULE_NUMBER_DOLIBARR = 230059;

    const GATEWAY_CODE = 'Lyra';
    const GATEWAY_NAME = 'Lyra Collect';
    const BACKOFFICE_NAME = 'Lyra Expert';
    const GATEWAY_URL = 'https://secure.lyra.com/vads-payment/';
    const SITE_ID = '12345678';
    const KEY_TEST = '1111111111111111';
    const KEY_PROD = '2222222222222222';
    const CTX_MODE = 'TEST';
    const SIGN_ALGO = 'SHA-256';
    const LANGUAGE = 'en';
    const SUPPORT_EMAIL = 'https://support.lyra.com/hc/fr/requests/new';

    const REST_URL = 'https://api.lyra.com/api-payment/';
    const STATIC_URL = 'https://static.lyra.com/static/';

    const CMS_IDENTIFIER = 'Dolibarr_22.x';
    const PLUGIN_VERSION = '2.0.0';

    const RETURN_MODE = 'POST';

    const REDIRECT_SUCCESS_TIMEOUT = '5';
    const REDIRECT_ERROR_TIMEOUT = '5';

    const PROCESS_URL = '/lyra/public/process.php';

    const MODE_FORM = '1';
    const MODE_EMBEDDED = '2';
    const MODE_EMBEDDED_EXT_WITH_LOGOS = '3';
    const MODE_EMBEDDED_EXT_WITHOUT_LOGOS = '4';

    const SITE_ID_REGEX = '^\d{8}$';
    const KEY_REGEX = '^[A-Za-z0-9]{16}$';
    const TEST_PRIVATE_KEY_REGEX = '^testpassword_[A-Za-z0-9]+$';
    const PROD_PRIVATE_KEY_REGEX = '^prodpassword_[A-Za-z0-9]+$';
    const TEST_PUBLIC_KEY_REGEX = ':testpublickey_[A-Za-z0-9]+$';
    const PROD_PUBLIC_KEY_REGEX = ':publickey_[A-Za-z0-9]+$';
    const HMAC_KEY_REGEX = '^[A-Za-z0-9]+$';
    const MASKED_CARD_NUMBER_REGEX = '^[0-9]+XXXXXX[0-9]{4}$';

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
        $this->numero = self::MODULE_NUMBER_DOLIBARR;
        $this->rights_class = 'lyra';
        $this->family = "interface";
        $this->module_position = '50';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = $langs->trans('LYRA_MODULE_DESC');
        $this->descriptionlong = '';
        $this->editor_name = 'Lyra Network';
        $this->editor_url = 'https://www.lyra.com';
        $this->version = self::PLUGIN_VERSION;
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
                'paymentlib',
                'invoicecard',
                'ordercard'
            ),
            'moduleforexternal' => 0,
            'websitetemplates' => 0,
            'captcha' => 0
        );

        // Data directories to create when module is enabled.
        $this->dirs = array("/lyra/temp");
        $this->config_page_url = array("lyra.php@lyra");

        $this->hidden = false;

        $this->depends = array();
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array("lyra@lyra");
        $this->phpmin = array(7, 0); // Minimum version of PHP required by module.
        $this->need_dolibarr_version = array(22, 0);
        $this->warnings_activation = array();
        $this->warnings_activation_ext = array();

        // Constants.
        $this->const = array(
            1 => array('LYRA_RETURN_MODE', 'chaine', self::RETURN_MODE, '', 1),
            2 => array('LYRA_REDIRECT_SUCCESS_TIMEOUT', 'chaine', self::REDIRECT_SUCCESS_TIMEOUT, '', 1),
            3 => array('LYRA_REDIRECT_SUCCESS_MESSAGE', 'chaine', $langs->trans('LYRA_REDIRECT_MESSAGE_TEXT'), '', 1),
            4 => array('LYRA_REDIRECT_ERROR_TIMEOUT', 'chaine', self::REDIRECT_ERROR_TIMEOUT, '', 1),
            5 => array('LYRA_REDIRECT_ERROR_MESSAGE', 'chaine', $langs->trans('LYRA_REDIRECT_MESSAGE_TEXT'), '', 1),
            6 => array('LYRA_VALIDATION_MODE', 'chaine', '', '', 1),
            7 => array('LYRA_LANGUAGE', 'chaine', self::LANGUAGE, '', 1),
            8 => array('LYRA_CTX_MODE', 'chaine', self::CTX_MODE, '', 1),
            9 => array('LYRA_SITE_ID', 'chaine', self::SITE_ID, '', 1),
            10 => array('LYRA_KEY_TEST', 'chaine', self::KEY_TEST, '', 1),
            11 => array('LYRA_KEY_PROD', 'chaine', self::KEY_PROD, '', 1),
            12 => array('LYRA_CARD_INFO_MODE', 'chaine', self::MODE_EMBEDDED_EXT_WITH_LOGOS, '', 1)
        );

        if (! isModEnabled("lyra")) {
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
        if ($result < 0) {
            return -1; // Do not activate module if error 'not allowed' returned when loading module SQL queries.
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

    public static function convertRestResult($answer)
    {
        if (! is_array($answer) || empty($answer)) {
            return array();
        }

        $transactions = self::getProperty($answer, 'transactions');
        $multiPaymentMean = false;

        if (! is_array($transactions) || empty($transactions)) {
            $transaction = $answer;
        } else {
            $transaction = $transactions[0];

            $multiPaymentMean = isset($transaction['transactionDetails']['cardDetails']['sequenceType'])
                    && $transaction['transactionDetails']['cardDetails']['sequenceType'] == 'MULTI_PAYMENT_MEAN';
        }

        $response = array();

        $response['vads_url_check_src'] = self::getProperty($answer, 'kr-src');
        $response['vads_order_cycle'] = self::getProperty($answer, 'orderCycle');
        $response['vads_order_status'] = self::getProperty($answer, 'orderStatus');

        if (($customer = self::getProperty($answer, 'customer'))) {
            $response['vads_cust_email'] = self::getProperty($customer, 'email');

            if ($billingDetails = self::getProperty($customer, 'billingDetails')) {
                $response['vads_language'] = self::getProperty($billingDetails, 'language');
            }
        }

        if ($orderDetails = self::getProperty($answer, 'orderDetails')) {
            $response['vads_order_id'] = self::getProperty($orderDetails, 'orderId');
        }

        $response = self::convertRestTransaction($response, $transaction);

        if ($multiPaymentMean) {
            $payment_seq = array(
                'trans_id' => $response['vads_trans_id'],
                'transactions' => array()
            );

            foreach ($transactions as $trs) {
                $payment_seq['transactions'][] = self::convertRestTransaction(array(), $trs, '');
            }

            $response['vads_card_brand'] = 'MULTI';
            $response['vads_payment_seq'] = json_encode($payment_seq);
            $response['vads_amount'] = self::getProperty($orderDetails, 'orderTotalAmount');
            $response['vads_authorized_amount'] = self::getProperty($orderDetails, 'orderTotalAmount');
        }

        return $response;
    }

    private static function convertRestTransaction($response, $transaction, $prefix = 'vads_') {
        $response[$prefix . 'result'] = self::getProperty($transaction, 'errorCode') ? self::getProperty($transaction, 'errorCode') : '00';
        $response[$prefix . 'extra_result'] = self::getProperty($transaction, 'detailedErrorCode');

        $response[$prefix . 'trans_status'] = self::getProperty($transaction, 'detailedStatus');
        $response[$prefix . 'trans_uuid'] = self::getProperty($transaction, 'uuid');
        $response[$prefix . 'operation_type'] = self::getProperty($transaction, 'operationType');
        $response[$prefix . 'effective_creation_date'] = self::getProperty($transaction, 'creationDate');
        $response[$prefix . 'payment_config'] = 'SINGLE'; // Only single payments are possible via REST API at this time.

        $response[$prefix . 'amount'] = self::getProperty($transaction, 'amount');
        $response[$prefix . 'currency'] = \Lyranetwork\Lyra\Sdk\Form\Api::getCurrencyNumCode(self::getProperty($transaction, 'currency'));

        if ($paymentToken = self::getProperty($transaction, 'paymentMethodToken')) {
            $response[$prefix . 'identifier'] = $paymentToken;
            $response[$prefix . 'identifier_status'] = 'CREATED';
        }

        if (($metadata = self::getProperty($transaction, 'metadata')) && is_array($metadata)) {
            foreach ($metadata as $key => $value) {
                $response[$prefix . 'ext_info_' . $key] = $value;
            }
        }

        if ($transactionDetails = self::getProperty($transaction, 'transactionDetails')) {
            $response[$prefix . 'sequence_number'] = self::getProperty($transactionDetails, 'sequenceNumber');

            // Workarround to adapt to REST API behavior.
            $effectiveAmount = self::getProperty($transactionDetails, 'effectiveAmount');
            $effectiveCurrency = \Lyranetwork\Lyra\Sdk\Form\Api::getCurrencyNumCode(self::getProperty($transactionDetails, 'effectiveCurrency'));

            if ($effectiveAmount && $effectiveCurrency) {
                $response[$prefix . 'effective_amount'] = $response[$prefix . 'amount'];
                $response[$prefix . 'effective_currency'] = $response[$prefix . 'currency'];
                $response[$prefix . 'amount'] = $effectiveAmount;
                $response[$prefix . 'currency'] = $effectiveCurrency;
            }

            $response[$prefix . 'warranty_result'] = self::getProperty($transactionDetails, 'liabilityShift');
            $response[$prefix . 'wallet'] = self::getProperty($transactionDetails, 'wallet');

            if ($cardDetails = self::getProperty($transactionDetails, 'cardDetails')) {
                $response[$prefix . 'trans_id'] = self::getProperty($cardDetails, 'legacyTransId'); // Deprecated.
                $response[$prefix . 'presentation_date'] = self::getProperty($cardDetails, 'expectedCaptureDate');

                $response[$prefix . 'card_brand'] = self::getProperty($cardDetails, 'effectiveBrand');
                $response[$prefix . 'card_number'] = self::getProperty($cardDetails, 'pan');
                $response[$prefix . 'expiry_month'] = self::getProperty($cardDetails, 'expiryMonth');
                $response[$prefix . 'expiry_year'] = self::getProperty($cardDetails, 'expiryYear');

                $response[$prefix . 'payment_option_code'] = self::getProperty($cardDetails, 'installmentNumber');


                if ($authorizationResponse = self::getProperty($cardDetails, 'authorizationResponse')) {
                    $response[$prefix . 'auth_result'] = self::getProperty($authorizationResponse, 'authorizationResult');
                    $response[$prefix . 'authorized_amount'] = self::getProperty($authorizationResponse, 'amount');
                }

                if (($authenticationResponse = self::getProperty($cardDetails, 'authenticationResponse'))
                    && ($value = self::getProperty($authenticationResponse, 'value'))) {
                        $response[$prefix . 'threeds_status'] = self::getProperty($value, 'status');
                        $response[$prefix . 'threeds_auth_type'] = self::getProperty($value, 'authenticationType');
                        if ($authenticationValue = self::getProperty($value, 'authenticationValue')) {
                            $response[$prefix . 'threeds_cavv'] = self::getProperty($authenticationValue, 'value');
                        }
                    } elseif (($threeDSResponse = self::getProperty($cardDetails, 'threeDSResponse'))
                        && ($authenticationResultData = self::getProperty($threeDSResponse, 'authenticationResultData'))) {
                            $response[$prefix . 'threeds_cavv'] = self::getProperty($authenticationResultData, 'cavv');
                            $response[$prefix . 'threeds_status'] = self::getProperty($authenticationResultData, 'status');
                            $response[$prefix . 'threeds_auth_type'] = self::getProperty($authenticationResultData, 'threeds_auth_type');
                    }
            }

            if ($fraudManagement = self::getProperty($transactionDetails, 'fraudManagement')) {
                if ($riskControl = self::getProperty($fraudManagement, 'riskControl')) {
                    $response['vads_risk_control'] = '';

                    foreach ($riskControl as $value) {
                        if (! isset($value['name']) || ! isset($value['result'])) {
                            continue;
                        }

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
        $supportedSignAlgos = array('sha256_hmac');

        // Check if the hash algorithm is supported.
        if (! in_array($data['kr-hash-algorithm'], $supportedSignAlgos)) {
            return false;
        }

        // On some servers, / can be escaped.
        $krAnswer = str_replace('\/', '/', $data['kr-answer']);

        $hash = hash_hmac('sha256', $krAnswer, $key);

        // Return true if calculated hash and sent hash are the same.
        return ($hash === $data['kr-hash']);
    }
}