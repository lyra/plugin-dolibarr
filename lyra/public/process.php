<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra Collect plugin for Dolibarr. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   http://www.gnu.org/licenses/gpl.html GNU General Public License (GPL v3)_
 */

/**
 * Instant payment notification file. Wait for payment gateway confirmation, then validate order.
 */
define('NOLOGIN',1);        // This means this output page does not require to be logged.
define('NOCSRFCHECK',1);    // We accept to go on this page from external web site.

/**
 * Simulate pending status Dolibarr invoice.
*/
define('PENDING_INVOICE', 2);

define ('NOT_BANK_PAYMENT', 'Payment is not recorded in bank account');

$res=0;

// Try master.inc.php using relative path.
if (! $res && file_exists('../master.inc.php')) {
    $res = @include '../master.inc.php';
}

if (! $res && file_exists('../../master.inc.php')) {
    $res = @include '../../master.inc.php';
}

if (! $res && file_exists('../../../master.inc.php')) {
    $res = @include '../../../master.inc.php';
}

if (! $res && file_exists('../../../../main.inc.php')) {
    $res = @include('../../../../main.inc.php');
}

dol_include_once('/lyra/lib/lyra.lib.php');

// Security check.
if (! isModEnabled('lyra')) {
    accessforbidden('', 1, 1, 1);
}

$langs->load('main');
$langs->load('other');
$langs->load('dict');
$langs->load('lyra@lyra');
$langs->loadLangs(array('main', 'other', 'dict', 'bills', 'companies', 'errors')); // File with generic data.

require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/don/class/don.class.php';

dol_include_once('/lyra/core/modules/modLyra.class.php');
dol_include_once('lyra/class/sdk-autoload.php');
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

global $conf, $notificationType;

$source = '';
$lyraResponse = null;

// Get response data.
$data = $_REQUEST;

// Check answer type (REDIRECT/EMBEDDED).
if (GETPOSTISSET('signature')) {
    $notificationType = (GETPOSTISSET('vads_hash') ? 'IPN' : 'client return');

    $lyraResponse = new \Lyranetwork\Lyra\Sdk\Form\Response(
        $data,
        getDolGlobalString('LYRA_CTX_MODE'),
        getDolGlobalString('LYRA_KEY_TEST'),
        getDolGlobalString('LYRA_KEY_PROD'),
        modLyra::SIGN_ALGO
    );

    lyraSyslog("Start processing {$notificationType} request in redirection mode.");

    // Check the authenticity of the request.
    if (! $lyraResponse->isAuthentified()) {
        lyraSyslog('Authentication failed: received invalid response with parameters: ' . print_r($data, true), LOG_ERR);
        printNotificationMessage($data, $source, 'INVALID_SIGNATURE');
    }
} elseif (GETPOSTISSET('kr-hash')) {
    $notificationType = ((GETPOST('kr-hash-key') == 'password') ? 'IPN' : 'client return');
    $key = ($notificationType === 'IPN') ? getLyraPrivateKey() : getLyraReturnKey();

    lyraSyslog("Start processing {$notificationType} request in embedded payment mode.");

    // Check the authenticity of the request.
    if (! modLyra::checkHash($_POST, $key)) {
        lyraSyslog('Authentication failed: received invalid response with parameters: ' . print_r($_POST, true), LOG_ERR);
        printNotificationMessage($data, $source, 'INVALID_SIGNATURE');
    }

    $answer = json_decode($data['kr-answer'], true);
    if (is_array($answer)) {
        $data = modLyra::convertRestResult($answer);
        $lyraResponse = new \Lyranetwork\Lyra\Sdk\Form\Response($data, null, null, null);

        if ($notificationType === 'IPN') {
            if ($lyraResponse->get('order_cycle') !== 'CLOSED') {
                lyraSyslog('Payment is not accepted but buyer can try to re-order. Do not process payment at this time.', LOG_WARNING);
                printNotificationMessage($data, $source, 'ERROR', ["Payment is in status {$lyraResponse->getTransStatus()}. Buyer can retry another payment attempt." ]);
            }

            if (in_array($lyraResponse->getTransStatus(), ['ABANDONED', 'EXPIRED'])) {
                lyraSyslog('Abandoned or Expired payment IPN ignored.', LOG_WARNING);
                printNotificationMessage($data, $source, 'ABANDONED');
            }
        }
    } else {
        lyraSyslog('Invalid data received. Content: ' . print_r($_POST, true), LOG_ERR);
        printNotificationMessage($data, $source, 'ERROR');
    }
} else {
    lyraSyslog('Invalid data received. Content: ' . print_r($_POST, true), LOG_ERR);
    printNotificationMessage($data, $source, 'ERROR');
}

if ($notificationType !== 'IPN') {
    llxHeaderLyra($langs->trans('LYRA_PAYMENT_FORM'));
}

$amount = $lyraResponse->get('amount');
$currency = \Lyranetwork\Lyra\Sdk\Form\Api::findCurrencyByNumCode($lyraResponse->get('currency'));

$orderId = $lyraResponse->get('order_id');
$fullTag = $lyraResponse->getExtInfo('full_tag');
$transStatus = $lyraResponse->get('trans_status');

// Get payment source (INVOICE, ORDER, FREE, DONATION...)
$source = getSourceType($fullTag);

lyraSyslog("Request authenticated for {$source} #{$orderId}, transaction status: {$lyraResponse->get('trans_status')}.");
lyraSyslog("Payment result for {$source} #{$orderId}: " . ($lyraResponse->get('error_message') ?: $lyraResponse->getLogMessage()));

if ($lyraResponse->isAcceptedPayment()) {
    lyraSyslog("Payment for {$source} #{$orderId} has been confirmed.");

    if ($lyraResponse->isPendingPayment()) {
        if ($source === 'invoice') {
            $invoice = new Facture($db);
            $result = $invoice->fetch('', $orderId);

            if (! verifyAmount($source, $data)) {
                exit;
            } else {
                $alreadyPaid = verifyAlreadyPaid($source, $orderId);
                if (! $alreadyPaid) {
                    setStatusNotePrivate($invoice, $transStatus);
                } else {
                    printNotificationMessage($data, $source, 'SUCCESS-BIS');
                }
            }
        }

        printNotificationMessage($data, $source, 'PENDING');
        exit;
    }

    $error = 0;
    $postactionmessages = [];
    $extPaymentId = ($source === 'free') ? $lyraResponse->get('trans_uuid') : '';

    $alreadyPaid = verifyAlreadyPaid($source, $orderId, $extPaymentId);
    // If no record of the payment exists.
    if (! $alreadyPaid) {
        if (in_array($source, ['free', 'invoice'])) {
            // If it is an invoice, consult the invoice by ref (order_id) and check the amount compared with signature amount.
            if ($source === 'invoice') {
                $invoice = new Facture($db);
                $result = $invoice->fetch('', $orderId);
                $id = $invoice->id;

                if (! verifyAmount($source, $data)) {
                    exit;
                }

                $notePrivate = $invoice->note_private;
                $key = '##';
                $pos = strripos($notePrivate, $key);
                if ($pos) {
                    $notePrivate = substr($notePrivate, $pos + 2);
                }

                $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'facture SET ';
                $sql .= " note_private='" . $notePrivate . "'";
                $sql .= ' WHERE rowid = ' . $id;
                $resql = $db->query($sql);
                if (! $resql) {
                    $error++;
                }
            }

            // If invoice is found or is a free amount that doesn't need to find an object.
            if ($result || $source === 'free') {
                $now = dol_now();
                $finalPaymentAmt = round($currency->convertAmountToFloat($amount), $currency->getDecimals());
                $transactionUuid = $lyraResponse->get('trans_uuid');
                $mode = getDolGlobalString('LYRA_CTX_MODE');
                $service = (($mode === 'TEST') ? 'Lyra Test' : 'Lyra Production');

                // Register of new payment on llx_paiement.
                $db->begin();

                // Creation of payment line.
                $paiement = new Paiement($db);
                $paiement->datepaye = $now;
                $paiement->amounts = array($invoice->id => $finalPaymentAmt); // All payments dispatching with invoice id.
                $paiement->multicurrency_code = array($invoice->id => $currency->getAlpha3());
                $paiement->multicurrency_amounts = array($invoice->id => $finalPaymentAmt); // All payments dispatching.
                $paymentTypeId = 0;

                $paymentTypeId = getDolGlobalInt('LYRA_PAYMENT_MODE_FOR_PAYMENTS');
                if (empty($paymentTypeId)) {
                    $paymentType = GETPOSTINT('paiementid') ?? null;
                    if (empty($paymentType)) {
                        $paymentType = 'VAD';
                    }

                    $paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);
                }

                $paiement->paiementid = $paymentTypeId;
                $paiement->note = getResponseMessage($lyraResponse);
                $paiement->ext_payment_id = $transactionUuid;
                $paiement->ext_payment_site = $service;
                $paiement->num_payment = $orderId;

                $total = 0;

                if (! $error) {
                    if ($source === 'free') { // Only create record on llx_paiement for free amount payment.
                        $db->begin();

                        $refPayment = $paiement->getNextNumRef(is_object($thirdParty) ? $thirdParty : '');
                        $total = $finalPaymentAmt;
                        $mtotal = $finalPaymentAmt;
                        $numPayment = $paiement->num_payment;
                        $note = $paiement->note;

                        $sql = 'INSERT INTO ' . MAIN_DB_PREFIX . 'paiement (entity, ref, datec, datep, amount, multicurrency_amount, fk_paiement, num_paiement, note, ext_payment_id, ext_payment_site, fk_user_creat)';
                        $sql.= ' VALUES (' . $conf->entity . ", '" . $db->escape($refPayment) . "', '" . $db->idate($now) . "', '" . $db->idate($paiement->datepaye ) . "', " . $total . ", " . $mtotal . ", " . $paiement->paiementid . ", '" . $db->escape($numPayment) . "', '" . $db->escape($note) . "', '" . $paiement->ext_payment_id . "', '" . $paiement->ext_payment_site . "', " . $user->id . ')';

                        $resql = $db->query($sql);
                        if ($resql) {
                            $paiement_id = $db->last_insert_id(MAIN_DB_PREFIX . 'paiement');
                        }
                    } else {
                        $paiement_id = $paiement->create($user, 1); // This include closing invoices and regenerating documents.
                    }

                    if ($paiement_id < 0) {
                        lyraSyslog($paiement->error . join("<br>\n", $paiement->errors), LOG_ERR);
                        $error++;
                    }
                }

                if (! $error && isModEnabled('bank') && getDolGlobalString('LYRA_DOLIBARR_BANK_ACCOUNT')) {
                    $bankAccountId = getDolGlobalString('LYRA_DOLIBARR_BANK_ACCOUNT');
                    if ($bankAccountId > 0) {
                        $label = '(CustomerInvoicePayment)';
                        if ($invoice->type == Facture::TYPE_CREDIT_NOTE) {
                            // Refund of a credit note.
                            $label = '(CustomerInvoicePaymentBack)';
                        }

                        if ($source === 'free') {
                           $label = '(FreePayment)';
                        }

                        $paiement->id = $paiement_id;
                        $result = $paiement->addPaymentToBank($user, 'payment', $label, $bankAccountId, '', '');

                        if ($result < 0) {
                            lyraSyslog($paiement->error . join("<br>\n", $paiement->errors), LOG_ERR);
                            $error++;
                        }
                    } else {
                        $postactionmessages[] = NOT_BANK_PAYMENT;
                    }
                }

                if (! $error) {
                    $db->query("COMMIT");
                } else {
                    $db->rollback();
                }
            }
        }

        if (! $error) {
            printNotificationMessage($data, $source, 'SUCCESS', $postactionmessages);
            exit;
        } else {
            printNotificationMessage($data, $source, 'ERROR', $postactionmessages);
            exit;
        }
    } else {
        lyraSyslog("Payment for {$source} #{$orderId} already saved.");
        printNotificationMessage($data, $source, 'SUCCESS-BIS', $postactionmessages);
        exit;
    }
} elseif ($lyraResponse->isCancelledPayment()) {
    lyraSyslog("Payment for {$source} #{$orderId} has been cancelled.");
    printNotificationMessage($data, $source, 'PAYMENT_ERROR');
} else {
    lyraSyslog("Payment for {$source} #{$orderId} has failed.");
    printNotificationMessage($data, $source, 'PAYMENT_ERROR');
}

/**
 * Obtain source type (free, invoice, order, donation, contractline, membersubscription).
 *
 * @param  string   $orderInfo  Information from answer
 * @return string   $source       (free, invoice, order, donation, contractline, membersubscription)
 */
function getSourceType ($orderInfo)
{
    $source = '';
    if (preg_match('#^TAG(.*)$#', $orderInfo)) {
        $source = 'free'; // Free amount.
    } elseif (preg_match('#^INV(.*)$#', $orderInfo)) {
        $source = 'invoice'; // Invoice.
    } elseif (preg_match('#^ORD(.*)$#', $orderInfo)) {
        $source = 'order';
    } elseif (preg_match('#^ORD(.*)$#', $orderInfo)) {
        $source = 'order'; // Order.
    } elseif (preg_match('#^DON(.*)$#', $orderInfo)) {
        $source = 'donation'; // Donation.
    } elseif (preg_match('#^COL(.*)$#', $orderInfo)) {
        $source = 'contractline'; // Contract ligne.
    } elseif (preg_match('#^MEM(.*)$#', $orderInfo)) {
        $source = 'membersubscription'; // Member subscription.
    }

    return $source;
}

/**
 * Print result of payment according to status answer.
 */
function printPaymentResult($data, $source, $resultType, $additionalMessages = array())
{
    global $conf, $mysoc, $langs, $notificationType;

    $suffix = '';
    $title = '';
    $printButton = true;
    $message = '';
    $messageDesc = '';

    if ($notificationType !== 'IPN') {
        // Show message.
        print '<span id="dolpaymentspan"></span>' . "\n";
        print '<div id="dolpaymentdiv" align="center">' . "\n";

        // Show logo.
        $width = 0;

        // Define logo and logosmall.
        $logosmall = $mysoc->logo_small;
        $logo = $mysoc->logo;
        $paramlogo = 'ONLINE_PAYMENT_LOGO_' . $suffix;

        if (getDolGlobalString($paramlogo)) {
            $logosmall = getDolGlobalString($paramlogo);
        } elseif (getDolGlobalString('ONLINE_PAYMENT_LOGO')) {
            $logosmall = getDolGlobalString('ONLINE_PAYMENT_LOGO');
        }

        // Define urlLogo.
        $urlLogo = '';
        if (! empty($logosmall) && is_readable($conf->mycompany->dir_output . '/logos/thumbs/' . $logosmall)) {
            $urlLogo = DOL_URL_ROOT . '/viewimage.php?modulepart=mycompany&amp;file=' . urlencode('logos/thumbs/' . $logosmall);
            $width = 150;
        } elseif (! empty($logo) && is_readable($conf->mycompany->dir_output . '/logos/' . $logo)) {
            $urlLogo = DOL_URL_ROOT . '/viewimage.php?modulepart=mycompany&amp;file=' . urlencode('logos/' . $logo);
            $width = 150;
        }

        // Output html code for logo - Logo from company (shop).
        if ($urlLogo) {
            print '<div class="backgreypublicpayment">';
            print '<center><img id="dolpaymentlogo" title="' . $title . '" src="' . $urlLogo . '"';

            if ($width) {
                print ' width="' . $width . '"';
            }

            print '></center>';
            print '</div>';
            print '<br>';
        }
    }

    require_once DOL_DOCUMENT_ROOT . '/core/lib/payments.lib.php';

    $amount = $data['vads_amount'] ?? null;
    $orderId = $data['vads_order_id'] ?? null;
    $status = $data['vads_trans_status'] ?? null;
    $url = '';
    $messageClic = '';

    if (in_array($resultType, ['PAYMENT_ERROR', 'ERROR'])) {
        $url = getOnlinePaymentUrl(0, $source, $orderId, $amount, $orderId);
        if ($status == 'REFUSED') {
            $message = $langs->trans("LYRA_REFUSED_PAYMENT_TEXT");
            $messageDesc = $langs->trans("LYRA_REFUSED_PAYMENT_MSG");
        } elseif ($status == 'CANCELLED') {
            $message = $langs->trans("LYRA_CANCELLED_PAYMENT_TEXT");
            $messageDesc = $langs->trans("LYRA_CANCELLED_PAYMENT_MSG");
        } elseif ($status == 'ABANDONED') {
            header("Location: " . $url);
        } else {
            $message = $langs->trans("LYRA_ERROR_PAYMENT_TEXT");
            $messageDesc = $langs->trans("LYRA_ERROR_PAYMENT_MSG");
        }

        $messageClic = $langs->trans("LYRA_TRY_AGAIN");
    } elseif (in_array($resultType, ['SUCCESS', 'SUCCESS-BIS'])) {
        if ($source === 'free') {
            $printButton = false;
        }

        $url = getOnlinePaymentUrl(0, $source, $orderId);
        $message = $langs->trans("LYRA_THANK_FOR_PAYMENT_TEXT");
        $messageDesc = $langs->trans("LYRA_SUCCESS_PAYMENT_TEXT");
        $messageClic = $langs->trans("LYRA_DETAILS");
    } elseif ($resultType === 'PENDING') {
        $url = getOnlinePaymentUrl(0, $source, $orderId);
        $message = $langs->trans("LYRA_PENDING_PAYMENT_TEXT");
        $messageDesc = $langs->trans("LYRA_PENDING_PAYMENT_MSG");
        $messageClic = $langs->trans("LYRA_DETAILS");
        if ($source === 'free') {
            $printButton = false;
        }
    } elseif ($resultType === 'INVALID_SIGNATURE') {
        $message = $langs->trans("LYRA_INVALID_SIGNATURE");
        $messageDesc = $langs->trans("LYRA_INVALID_SIGNATURE_DESC");
        $printButton = false;
    } elseif ($resultType === 'INVALID_AMOUNT') {
         $message = $langs->trans("LYRA_INVALID_AMOUNT");
         $messageDesc = $langs->trans("LYRA_INVALID_AMOUNT_DESC");
         $printButton = false;
    }

    // Output payment summary form.
    if ($notificationType !== 'IPN') {
        print '<center>' . "\n";
        print '<table id="dolpaymenttable" summary="Payment form">' . "\n";
        print '<tr><td align="center">';

        print '<table with="100%" id="tablepublicpayment">';
        print '<br>';
        print '<tr class="liste_total"><td align="left" colspan="2">' . $message . '</td></tr>' . "\n";

        if ($additionalMessages) {
            foreach($additionalMessages as $additionalMessage) {
               print '<tr class="liste_total"><td align="left" colspan="2">' . $additionalMessage . '</td></tr>' . "\n";
            }
        }

        print '<tr><td><br></td></tr>';
        printSummaryPaymentDetails($data, $source);
        print '<tr><td><br></td></tr>';

        print '<tr>';
        print '<td align="left" colspan="2">' . $messageDesc . '</td>';
        print '</tr>' . "\n";

        if (in_array($resultType, ['SUCCESS', 'SUCCESS-BIS'])) {
            if ($source == 'order') {
                print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("OrderBilled") . '</span>';
            } elseif ($source == 'invoice') {
                print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("InvoicePaid") . '</span>';
            } elseif ($source == 'donation') {
               print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("DonationPaid") . '</span>';
            } elseif ($source == 'membersubscription') {
                $langs->load("members");
                print '<br><span class="amountpaymentcomplete">' . $langs->trans("MembershipPaid", dol_print_date($object->datefin, 'day')) . '</span><br>';
                print '<span class="opacitymedium">' . $langs->trans("PaymentWillBeRecordedForNextPeriod") . '</span><br>';
            }
        }

        // If the payment is not successful show redirect button with calculated url.
        if ($printButton) {
            print '<tr>';
            print '<td align="center" colspan="2" ><br><br><div  class="button buttonpayment"><a style="color: white;" href="' . $url . '">' . $messageClic . '</a></div></td>';
            print '</tr>';
        }

        print '</table>';
        print '</td></tr>' . "\n";
        print '</table>' . "\n";
        print '</center>' . "\n";
    } else {
        print $messageDesc;
        if ($resultType === 'ERROR' || $resultType === 'INVALID_SIGNATURE' || $resultType === 'INVALID_AMOUNT' ) {
            print ' || KO';
        } else {
            print ' || OK';
        }
    }
}

/**
 * Print summary data of the payment object source (free, invoice...).
 */
function printSummaryPaymentDetails($data, $source)
{
    global $langs, $mysoc, $conf, $db;

    $var = false;
    $creditor = $mysoc->name;
    $currency = \Lyranetwork\Lyra\Sdk\Form\Api::findCurrencyByAlphaCode($conf->currency);
    $amount = isset($data['vads_amount']) ? round($currency->convertAmountToFloat($data['vads_amount']), $currency->getDecimals()) : null;
    $orderId = $data['vads_order_id'] ?? null;
    $fullTag = $data['vads_ext_info_full_tag'] ?? null;
    $custName = isset($data['vads_cust_first_name']) ?? '';

    if ($source == 'free') {
        // Creditor.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_CREDITOR");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $creditor . '</b>';
        print '</td></tr>' . "\n";

        // Amount.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_AMOUNT");
        if (empty($amount)) {
            print ' (' . $langs->trans("LYRA_TO_COMPLETE") . ')';
        }

        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">';
        if (empty($amount) || ! is_numeric($amount)) {
            print '<input class="flat maxwidth75" type="text" name="newamount" value="' . price2num(GETPOST("newamount", "alpha"), 'MT') . '">';
        } else {
            print '<b>' . price($amount) . '</b>';
        }

        // Currency.
        print ' <b>' . $langs->trans("Currency" . $conf->currency) . '</b>';
        print '</td></tr>' . "\n";

        // Tag.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_PAYMENT_CODE");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b style="word-break: break-all;">' . $fullTag . '</b>';
        print '</td></tr>' . "\n";
    }

    // Payment on customer order.
    if ($source == 'order') {
        $langs->load("orders");

        require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';

        $order = new Commande($db);
        $result = $order->fetch('', $orderId);
        if ($result <= 0) {
            $mesg = $order->error;
            $error++;
        } else {
            $result = $order->fetch_thirdparty($order->socid);
        }

        $fullTag = dol_string_unaccent($fullTag);

        // Creditor.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_CREDITOR");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $creditor . '</b>';
        print '</td></tr>' . "\n";

        // Debitor.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_THIRD_PARTY");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $order->thirdparty->name . '</b>';
        print '</td></tr>' . "\n";

        // Object.
        $text = '<b>' . $langs->trans("LYRA_PAYMENT_ORDER_REF", $order->ref) . '</b>';
        if (GETPOST('desc', 'alpha')) {
            $text = '<b>' . $langs->trans(GETPOST('desc', 'alpha')) . '</b>';
        }

        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_DESIGNATION");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">' . $text;

        print '</td></tr>' . "\n";

        // Amount.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_AMOUNT");
        if (empty($amount)) {
            print ' (' . $langs->trans("LYRA_TO_COMPLETE") . ')';
        }

        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">';
        if (empty($amount) || ! is_numeric($amount)) {
            print '<input class="flat maxwidth75" type="text" name="newamount" value="' . price2num(GETPOST("newamount", "alpha"), 'MT') . '">';
        } else {
            print '<b>' . price($amount) . '</b>';
        }

        // Currency.
        print ' <b>' . $langs->trans("Currency" . $conf->currency) . '</b>';
        print '</td></tr>' . "\n";

        // Tag.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_PAYMENT_CODE");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b style="word-break: break-all;">' . $fullTag . '</b>';
        print '</td></tr>' . "\n";
    }

    // Payment of customer invoice.
    if ($source == 'invoice') {
        $langs->load("bills");

        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

        $invoice = new Facture($db);
        $result = $invoice->fetch('', $orderId);
        if ($result <= 0) {
            $mesg = $invoice->error;
            $error++;
        } else {
            $result = $invoice->fetch_thirdparty($invoice->socid);
        }

        $object = $invoice;
        $fullTag = dol_string_unaccent($fullTag);

        // Creditor.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_CREDITOR");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $creditor . '</b>';
        print '</td></tr>' . "\n";

        // Debitor.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_THIRD_PARTY");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $invoice->thirdparty->name . '</b>';
        print '</td></tr>' . "\n";

        // Object.
        $text = '<b>' . $langs->trans("PaymentInvoiceRef", $invoice->ref) . '</b>';
        if (GETPOST('desc', 'alpha')) {
            $text = '<b>' . $langs->trans(GETPOST('desc', 'alpha')) . '</b>';
        }

        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_DESIGNATION");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">' . $text;
        print '</td></tr>' . "\n";

        // Amount.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("PaymentAmount");
        if (empty($amount) && empty($object->paye)) {
            print ' (' . $langs->trans("LYRA_TO_COMPLETE") . ')';
        }

        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">';
        if (empty($object->paye)) {
            if (empty($amount) || ! is_numeric($amount)) {
                print '<input class="flat maxwidth75" type="text" name="newamount" value="' . price2num(GETPOST("newamount", "alpha"), 'MT') . '">';
            } else {
                print '<b>' . price($amount) . '</b>';
            }
        } else {
            print '<b>' . price($object->total_ttc, 1, $langs) . '</b>';
        }

        // Currency.
        print ' <b>' . $langs->trans("Currency" . $conf->currency) . '</b>';
        print '</td></tr>' . "\n";

        // Tag.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_PAYMENT_CODE");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b style="word-break: break-all;">' . $fullTag . '</b>';
        print '</td></tr>' . "\n";
    }

    // Payment on contract line.
    if ($source == 'contractline') {
        $langs->load("contracts");

        require_once DOL_DOCUMENT_ROOT . '/contrat/class/contrat.class.php';

        $contract = new Contrat($db);
        $contractline = new ContratLigne($db);
        $result = $contractline->fetch('', $orderId);

        if ($result <= 0) {
            $mesg = $contractline->error;
            $error++;
        } else {
            if ($contractline->fk_contrat > 0) {
                $result = $contract->fetch($contractline->fk_contrat);
                if ($result > 0) {
                    $result = $contract->fetch_thirdparty($contract->socid);
                } else {
                    $mesg = $contract->error;
                    $error++;
                }
            } else {
                $mesg = 'ErrorRecordNotFound';
                $error++;
            }
        }

        $object = $contractline;
        if ($action != 'dopayment') { // Do not change amount if we just click on first dopayment.
            $amount = $contractline->total_ttc;
            if ($contractline->fk_product && getDolGlobalString('PAYMENT_USE_NEW_PRICE_FOR_CONTRACTLINES')) {
                $product = new Product($db);
                $result = $product->fetch($contractline->fk_product);

                if (getDolGlobalString('PRODUIT_MULTIPRICES')) {
                    $pu_ht = $product->multiprices[$contract->thirdparty->price_level];
                    $pu_ttc = $product->multiprices_ttc[$contract->thirdparty->price_level];
                    $price_base_type = $product->multiprices_base_type[$contract->thirdparty->price_level];
                } else {
                    $pu_ht = $product->price;
                    $pu_ttc = $product->price_ttc;
                    $price_base_type = $product->price_base_type;
                }

                $amount = $pu_ttc;
                if (empty($amount)) {
                    dol_print_error('', 'ErrorNoPriceDefinedForThisProduct');
                    exit;
                }
            }

            if (GETPOST("amount", 'int')) {
                $amount = GETPOST("amount", 'int');
            }

            $amount = price2num($amount);
        }

        if (GETPOST('fulltag', 'alpha')) {
           $fullTag = GETPOST('fulltag', 'alpha');
        } else {
            $fullTag = 'COL=' . $contractline->id . '.CON=' . $contract->id . '.CUS=' . $contract->thirdparty->id . '.DAT=' . dol_print_date(dol_now(), '%Y%m%d%H%M%S');
            if (! empty($TAG)) {
                $fullTag .= '.TAG=' . $TAG;
            }
        }

        $fullTag = dol_string_unaccent($fullTag);

        $qty = 1;
        if (GETPOST('qty')) {
            $qty = GETPOST('qty');
        }

        // Creditor.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_CREDITOR");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $creditor . '</b>';
        print '</td></tr>' . "\n";

        // Debitor.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_THIRD_PARTY");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $custName . '</b>';
        print '</td></tr>' . "\n";

        // Object.
        $text = '<b>' . $langs->trans("PaymentRenewContractId", $orderId, $contractline->ref) . '</b>';
        if ($contractline->fk_product) {
            $contractline->fetch_product();
            $text .= '<br>' . $contractline->product->ref . ($contractline->product->label ? ' - ' . $contractline->product->label : '');
        }

        if ($contractline->description) {
            $text .= '<br>' . dol_htmlentitiesbr($contractline->description);
        }

        if ($contractline->date_fin_validite) {
            $text .= '<br>' . $langs->trans("ExpiredSince") . ': ' . dol_print_date($contractline->date_fin_validite);
        }

        if (GETPOST('desc', 'alpha')) {
            $text = '<b>' . $langs->trans(GETPOST('desc', 'alpha')) . '</b>';
        }

        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_DESIGNATION");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">' . $text;
        print '<input type="hidden" name="source" value="' . dol_escape_htmltag($source) . '">';
        print '<input type="hidden" name="ref" value="' . dol_escape_htmltag($contractline->ref) . '">';
        print '<input type="hidden" name="dol_id" value="' . dol_escape_htmltag($contractline->id) . '">';

        $directdownloadlink = $contract->getLastMainDocLink('contract');
        if ($directdownloadlink) {
            print '<br><a href="' . $directdownloadlink . '">';
            print img_mime($contract->last_main_doc, '');
            print $langs->trans("DownloadDocument") . '</a>';
        }

        print '</td></tr>' . "\n";

        // Quantity.
        $label = $langs->trans("Quantity");
        $qty = 1;
        $duration = '';
        if ($contractline->fk_product && $contractline->product->isService() && ($contractline->product->duration_value > 0)) {
            $label = $langs->trans("Duration");

            if ($contractline->product->duration_value > 1) {
                $dur = array("h"=>$langs->trans("Hours"), "d"=>$langs->trans("DurationDays"), "w"=>$langs->trans("DurationWeeks"), "m"=>$langs->trans("DurationMonths"), "y"=>$langs->trans("DurationYears"));
            } else {
                $dur = array("h"=>$langs->trans("Hour"), "d"=>$langs->trans("DurationDay"), "w"=>$langs->trans("DurationWeek"), "m"=>$langs->trans("DurationMonth"), "y"=>$langs->trans("DurationYear"));
            }

            $duration = $contractline->product->duration_value . ' ' . $dur[$contractline->product->duration_unit];
        }

        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $label . '</td>';
        print '<td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . ($duration ? $duration : $qty) . '</b>';
        print '<input type="hidden" name="newqty" value="' . dol_escape_htmltag($qty) . '">';
        print '</b></td></tr>' . "\n";

        // Amount.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_AMOUNT");
        if (empty($amount)) {
            print ' (' . $langs->trans("LYRA_TO_COMPLETE") . ')';
        }

        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">';
        if (empty($amount) || ! is_numeric($amount)) {
            print '<input type="hidden" name="amount" value="'.GETPOST("amount", 'int') . '">';
            print '<input class="flat maxwidth75" type="text" name="newamount" value="' . price2num(GETPOST("newamount", "alpha"), 'MT') . '">';
        } else {
            print '<b>'.price($amount) . '</b>';
            print '<input type="hidden" name="amount" value="' . $amount . '">';
            print '<input type="hidden" name="newamount" value="' . $amount . '">';
        }

        // Currency.
        print ' <b>' . $langs->trans("Currency" . $conf->currency) . '</b>';
        print '<input type="hidden" name="currency" value="' . $currency->getAlpha3() . '">';
        print '</td></tr>' . "\n";

        // Tag.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_PAYMENT_CODE");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b style="word-break: break-all;">' . $fullTag . '</b>';
        print '</td></tr>' . "\n";
    }

    // Payment on member subscription.
    if ($source == 'membersubscription') {
        $langs->load("members");

        require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent.class.php';
        require_once DOL_DOCUMENT_ROOT . '/adherents/class/subscription.class.php';

        $member = new Adherent($db);
        $result = $member->fetch('', $orderId);
        if ($result <= 0) {
            $mesg = $member->error;
            $error++;
        } else {
            $member->fetch_thirdparty();
            $subscription = new Subscription($db);
        }

        $object = $member;
        $fullTag = dol_string_unaccent($fullTag);

        // Creditor.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_CREDITOR");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $creditor . '</b>';
        print '</td></tr>' . "\n";

        // Debitor.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("Member");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b>';
        if ($member->morphy == 'mor' && ! empty($member->societe)) {
            print $member->societe;
        } else {
            print $member->getFullName($langs);
        }

        print '</b>';
        print '</td></tr>' . "\n";

        // Object.
        $text = '<b>' . $langs->trans("PaymentSubscription") . '</b>';
        if (GETPOST('desc', 'alpha')) {
            $text = '<b>' . $langs->trans(GETPOST('desc', 'alpha')) . '</b>';
        }

        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_DESIGNATION");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">' . $text;
        print '</td></tr>' . "\n";

        if ($object->datefin > 0) {
            print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("DateEndSubscription");
            print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">' . dol_print_date($member->datefin, 'day');
            print '</td></tr>' . "\n";
        }

        if ($member->last_subscription_date || $member->last_subscription_amount) {
            // Last subscription date.
            print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LastSubscriptionDate");
            print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">' . dol_print_date($member->last_subscription_date, 'day');
            print '</td></tr>' . "\n";

            // Last subscription amount.
            print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LastSubscriptionAmount");
            print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">' . price($member->last_subscription_amount);
            print '</td></tr>' . "\n";

            if (empty($amount) && ! GETPOST('newamount', 'alpha')) {
                $_GET['newamount'] = $member->last_subscription_amount;
            }
        }

        // Amount.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_AMOUNT");
        if (empty($amount)) {
            if (empty(getDolGlobalString('MEMBER_NEWFORM_AMOUNT'))) {
                print ' (' . $langs->trans("LYRA_TO_COMPLETE");
            }

            if (getDolGlobalString('MEMBER_EXT_URL_SUBSCRIPTION_INFO')) {
                print ' - <a href="' . getDolGlobalString('MEMBER_EXT_URL_SUBSCRIPTION_INFO') . '" rel="external" target="_blank">' . $langs->trans("SeeHere") . '</a>';
            }

            if (empty(getDolGlobalString('MEMBER_NEWFORM_AMOUNT'))) {
                print ')';
            }
        }

        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">';
        $valtoshow = '';
        if (empty($amount) || ! is_numeric($amount)) {
            $valtoshow = price2num(GETPOST("newamount", 'alpha'), 'MT');

            // Force default subscription amount to value defined into constant...
            if (empty($valtoshow)) {
                if (getDolGlobalString('MEMBER_NEWFORM_EDITAMOUNT')
                    && getDolGlobalString('MEMBER_NEWFORM_AMOUNT')) {
                    $valtoshow = getDolGlobalString('MEMBER_NEWFORM_AMOUNT');
                } elseif (getDolGlobalString('MEMBER_NEWFORM_AMOUNT')) {
                    $amount = getDolGlobalString('MEMBER_NEWFORM_AMOUNT');
                }
            }
        }

        if (empty($amount) || ! is_numeric($amount)) {
            if (getDolGlobalString('MEMBER_MIN_AMOUNT') && $valtoshow) {
                $valtoshow = max(getDolGlobalString('MEMBER_MIN_AMOUNT'), $valtoshow);
            }

            print '<input class="flat maxwidth75" type="text" name="newamount" value="' . $valtoshow . '">';
        } else {
            $valtoshow = $amount;
            if (getDolGlobalString('MEMBER_MIN_AMOUNT') && $valtoshow) {
                $valtoshow = max(getDolGlobalString('MEMBER_MIN_AMOUNT'), $valtoshow);
            }

            print '<b>' . price($valtoshow) . '</b>';
        }

        // Currency.
        print ' <b>' . $langs->trans("Currency" . $currency) . '</b>';
        print '</td></tr>' . "\n";

        // Tag.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_PAYMENT_CODE");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b style="word-break: break-all;">' . $fullTag . '</b>';
        print '</td></tr>' . "\n";
    }

    // Payment on donation.
    if ($source == 'donation') {
        $langs->load("don");

        require_once DOL_DOCUMENT_ROOT . '/don/class/don.class.php';

        $don = new Don($db);
        $result = $don->fetch($ref);
        if ($result <= 0) {
            $mesg = $don->error;
            $error++;
        } else {
            $don->fetch_thirdparty();
        }

        $fullTag = dol_string_unaccent($fullTag);

        // Creditor.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_CREDITOR");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $creditor . '</b>';
        print '</td></tr>' . "\n";

        // Debitor.
         print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_THIRD_PARTY");
         print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b>';
         if ($don->morphy == 'mor' && ! empty($don->societe)) {
             print $don->societe;
         } else {
             print $don->getFullName($langs);
         }

         print '</b>';
         print '</td></tr>' . "\n";

         // Object.
         $text = '<b>' . $langs->trans("PaymentDonation") . '</b>';
         if (GETPOST('desc', 'alpha')) {
             $text = '<b>' . $langs->trans(GETPOST('desc', 'alpha')) . '</b>';
         }

         print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_DESIGNATION");
         print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">' . $text;
         print '</td></tr>' . "\n";

         // Amount.
         print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_AMOUNT");
         if (empty($amount)) {
             if (empty(getDolGlobalString('MEMBER_NEWFORM_AMOUNT'))) {
                 print ' (' . $langs->trans("LYRA_TO_COMPLETE");
             }

             if (getDolGlobalString('MEMBER_EXT_URL_SUBSCRIPTION_INFO')) {
                 print ' - <a href="' . getDolGlobalString('MEMBER_EXT_URL_SUBSCRIPTION_INFO') . '" rel="external" target="_blank">' . $langs->trans("SeeHere") . '</a>';
             }

             if (empty(getDolGlobalString('MEMBER_NEWFORM_AMOUNT'))) {
                 print ')';
            }
        }

        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">';
        $valtoshow = '';
        if (empty($amount) || ! is_numeric($amount)) {
            $valtoshow = price2num(GETPOST("newamount", 'alpha'), 'MT');
            // Force default subscription amount to value defined into constant...
            if (empty($valtoshow)) {
                if (getDolGlobalString('MEMBER_NEWFORM_EDITAMOUNT')
                    && getDolGlobalString('MEMBER_NEWFORM_AMOUNT')) {
                    $valtoshow = getDolGlobalString('MEMBER_NEWFORM_AMOUNT');
                } elseif (getDolGlobalString('MEMBER_NEWFORM_AMOUNT')) {
                   $amount = getDolGlobalString('MEMBER_NEWFORM_AMOUNT');
                }
            }
        }

        if (empty($amount) || ! is_numeric($amount)) {
            if (getDolGlobalString('MEMBER_MIN_AMOUNT') && $valtoshow) {
                $valtoshow = max(getDolGlobalString('MEMBER_MIN_AMOUNT'), $valtoshow);
            }

            print '<input class="flat maxwidth75" type="text" name="newamount" value="' . $valtoshow . '">';
        } else {
            $valtoshow = $amount;
            if (getDolGlobalString('MEMBER_MIN_AMOUNT') && $valtoshow) {
                $valtoshow = max(getDolGlobalString('MEMBER_MIN_AMOUNT'), $valtoshow);
            }

            print '<b>' . price($valtoshow) . '</b>';
         }

         // Currency.
         print ' <b>' . $langs->trans("Currency" . $currency) . '</b>';
         print '</td></tr>' . "\n";

         // Tag.
         print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_PAYMENT_CODE");
         print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b style="word-break: break-all;">' . $fullTag . '</b>';
         print '</td></tr>' . "\n";
    }
}

/**
 * Verify if source is already paid.
 */
function verifyAlreadyPaid($source, $ref, $extPaymentId = '')
{
    global $db, $langs;

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

        case 'free':
            $object = new Paiement($db);
            $sql = 'SELECT p.rowid, p.ref, p.ext_payment_id, p.ext_payment_site, p.fk_bank,';
            $sql.= ' p.num_paiement as num_payment, p.note';
            $sql.= ' FROM ' . MAIN_DB_PREFIX . 'paiement as p LEFT JOIN ' . MAIN_DB_PREFIX . 'c_paiement as c ON p.fk_paiement = c.id';
            $sql.= ' WHERE p.entity IN (' . getEntity('invoice') . ')';
            if ($ref) {
                $sql.= " and p.num_paiement = '" . $ref . "'";
            }

            if ($extPaymentId) {
                $sql.= " and p.ext_payment_id  = '" . $extPaymentId . "'";
            }

            $numRows = $db->query($sql)->num_rows;
            if ($numRows > 0) {
                return true;
            }

        default:
            $object = null;
            break;
    }

    if ($object) {
        $result = null;
        if (! empty($ref)) {
            $result = $object->fetch('', $ref); // Find by ref.
        }

        if ($source == 'donation') {
            $result = $object->fetch($ref); // Find by rowid (Donation).
        }

        if ($result) { // Order found.
            if (($source == 'order' && $object->billed)
                || ($source == 'invoice' && $object->paye == 1)
                || ($source == 'donation' && $object->paid)
                || ($source == 'membersubscription' && $object->datefin > dol_now())) {
                return true;
            }

            return false;
        }
     }

     return false;
}

/**
 * Compare if source amount is equal to answer IPN amount.
 */
function verifyAmount($source, $data)
{
    global $db, $langs, $notificationType;

    $orderId = $data['vads_order_id'];
    $currency = \Lyranetwork\Lyra\Sdk\Form\Api::findCurrencyByNumCode($data['vads_currency']);
    $amount = round($currency->convertAmountToFloat($data['vads_amount']), $currency->getDecimals());

    if ($source === 'invoice') {
        $invoice = new Facture($db);
        $invoice->fetch('', $orderId);

        $factureAmount = (float) $invoice->total_ttc;

        if ($factureAmount !== (float) $amount) {
            $alreadyPaid = verifyAlreadyPaid($source, $orderId);
            if (! $alreadyPaid) {
               setStatusNotePrivate($invoice, 'INVALID_AMOUNT');
            } else { // If you want to notify already payment intead of invalid amount activate this code.
                printNotificationMessage($data, $source, 'SUCCESS-BIS');
            }

            if ($notificationType !== 'IPN') {
                $additionalInfo = array($langs->trans('LYRA_IPN_AMOUNT', $amount), $langs->trans('LYRA_INVOICE_AMOUNT', strval($factureAmount)));
            }

            printNotificationMessage($data, $source, 'INVALID_AMOUNT', $additionalInfo);
            return false;
        }

        return true;
    }

    return true;
}

/**
 * Set field private_note on database with the status from answer IPN.
 */
function setStatusNotePrivate($invoice, $transStatus)
{
    global $db;

    $error = 0;
    $id = $invoice->id;
    $db->begin();

    $notePrivate = $invoice->note_private; // Example '##STATUS=WAITING_AUTHORISATION## Test Private Note New Facture'.
    $key = '##';

    // If exists another pending status is eliminated.
    $pos = strripos($notePrivate, $key); // The last appearance is found.
    if ($pos) {
        $notePrivate = substr($notePrivate, $pos + 2);
    }

    // Generate and replace new status.
    $key = '##';
    $notePrivate = $key . 'STATUS=' . $transStatus . $key . $notePrivate;

    $sql = 'UPDATE ' . MAIN_DB_PREFIX . 'facture SET ';
    $sql .= " note_private='" . $notePrivate . "'";
    $sql .= ' WHERE rowid = ' . $id;

    $resql = $db->query($sql);
    if (! $resql) {
        $error++;
    }

    if (! $error) {
        $db->commit();
    } else {
        $db->rollback();
    }
}

/**
 * @return string   string with signature calculated (Signature or Hash)
 * @param  array    $data array with params with data from answer
 * @param  string   $source Dolibarr payment methods (Free, Invoice, Order, Donation...)
 * @param  string   $messageType general type of message (ERROR, SUCCESS, INVALIDA_AMOUNT, INVALID_SIGNATURE)
 * @param  string   $supportMessage Message sent to IPN socket
 * @param  array    $additionalInfo Additional Info to add to final notification
 */
function printNotificationMessage($data, $source, $messageType, $additionalInfo = array())
{
    global $notificationType;

    lyraSyslog("End processing {$notificationType}.");

    if ($notificationType !== 'IPN') {
        printPaymentResult($data, $source, $messageType, $additionalInfo);
        exit;
    } else {
        switch ($messageType) {
            case 'PAYMENT_ERROR':
                $msg = 'KO-Payment failure.';
                break;

            case 'INVALID_SIGNATURE':
                $msg = 'KO-An error occurred while computing the signature.';
                break;

            case 'INVALID_AMOUNT':
                $msg = 'KO-Total paid is different from order amount.';
                break;

            case 'ABANDONED':
                $msg = 'KO-Payment abandoned or expired.';
                break;

            case 'SUCCESS':
            case 'PENDING':
                $msg = "OK-Accepted payment, {$source} has been updated.";
                break;

            case 'SUCCESS-BIS':
                $msg = 'OK-Accepted payment, already registered.';
                break;

            default:
                $msg = 'KO' . (isset($additionalInfo) && is_array($additionalInfo) ? '-' . $additionalInfo[0] : '');
                break;
        }

        print $msg;
        exit;
    }
}

function getResponseMessage($lyraResponse)
{
    global $langs;

    $message = '';

    // Save transaction details to payment object.
    if ($lyraResponse->get('card_brand') === 'MULTI') {
        $data = json_decode($lyraResponse->get('payment_seq'), true);
        $transactions = $data['transactions'];

        $message .= "<b>". $langs->trans('LYRA_TRANSACTIONS') . ": </b>";

        foreach ($transactions as $trs) {
            $message .= "<p>" . getTransInfo($trs) . "</p>" ;
        }
    } else {
        $message .= getTransInfo($lyraResponse) ;
    }

    return $message;
}

function getTransInfo($trs)
{
    global $langs;

    $paymentMeans = getTransProperty($trs, 'wallet') ?? getTransProperty($trs, 'card_brand');

    $message = "<br><b>" . $langs->trans('LYRA_MEANS_PAYMENT') . ": </b>" . $paymentMeans;

    $cardNumber = getTransProperty($trs, 'card_number');
    if (checkRegex(modLyra::MASKED_CARD_NUMBER_REGEX, $cardNumber)) {
        $message .= "<br><b>" . $langs->trans('LYRA_CARD_NUMBER') . ': </b>' . $cardNumber;
    }

    if (($expiryMonth = getTransProperty($trs, 'expiry_month')) & ($expiryYear = getTransProperty($trs, 'expiry_year'))) {
        $expiry = str_pad($expiryMonth, 2, '0', STR_PAD_LEFT) . " / " . $expiryYear;
        $message .= "<br><b>" . $langs->trans('LYRA_EXPIRATION_DATE') . ": </b>" . $expiry;
    }

    // Transaction status.
    $message .= "<br><b>" . $langs->trans('LYRA_TRANS_STATUS') . ": </b>" . getTransProperty($trs,'trans_status');

    // Transaction UUID.
    $message .= "<br><b>" . $langs->trans('LYRA_TRANS_UUID') . ": </b>" . getTransProperty($trs,'trans_uuid');

    // 3DS extra message.
    $message .= "<br><b>" . $langs->trans('LYRA_THREEDS_AUTH') . ": </b>";
    if ($threedsStatus = getTransProperty($trs, 'threeds_status')) {
        $message .= getThreedsStatus($threedsStatus);
        $message .= ($threedsAuthType = getTransProperty($trs, 'threeds_auth_type')) ? "<br><b>" . $langs->trans('LYRA_THREEDS_AUTH_TYPE') . ": </b>" . $threedsAuthType : '';
    } else {
        $message .= 'UNAVAILABLE';
    }

    return $message;
}

function getTransProperty($transObject, $propertyName)
{
    if (is_array($transObject) && ! empty($transObject)) {
        return isset($transObject[$propertyName]) ? $transObject[$propertyName] : null;
    } elseif ($transObject instanceof \Lyranetwork\Lyra\Sdk\Form\Response) {
        return $transObject->get($propertyName);
    }

    return null;
}

function getThreedsStatus($status)
{
    $threedsStatuses = [
        'Y' => 'SUCCESS',
        'N' => 'FAILED',
        'U' => 'UNAVAILABLE',
        'A' =>'ATTEMPT'
    ];

    return isset($threedsStatuses[$status]) ? $threedsStatuses[$status] : $status;
}