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
 * Instant payment notification file. Wait for payment gateway confirmation, then validate order.
 */
define("NOLOGIN",1);        // This means this output page does not require to be logged.
define("NOCSRFCHECK",1);    // We accept to go on this page from external web site.

/**
 * Simulate pending status Dolibarr invoice.
*/
define("PENDING_INVOICE", 2);

// IPN messages.
define ("ERROR_ANSWER_TYPE",'Answer Type Error. Signature or kr-hash was not found in answer. Error creating redirect form or embedded form.');
define ("CANCELLED_STATUS_TYPE",'Cancelled status was found');
define ("ERROR_INVALID_AMOUNT",'Invalid Amount');
define ("ERROR_INVALID_SIGNATURE",'An error has occurred in the calculation of the signature');
define ("PENDING_STATUS",'Payment Pending Verification');
define ("SUCCESSFUL_PAYMENT",'Successful Payment');
define ("ERROR_PAYMENT",'Payment Error');
define ("SUCCESSFUL_ALREADY_PAYMENT", 'Payment Already Made');
define ("NOT_BANK_PAYMENT", 'Payment is not recorded in bank account');

$res=0;

// Try master.inc.php using relative path.
if (! $res && file_exists("../master.inc.php")) {
    $res = @include "../master.inc.php";
}

if (! $res && file_exists("../../master.inc.php")) {
    $res = @include "../../master.inc.php";
}

if (! $res && file_exists("../../../master.inc.php")) {
    $res = @include "../../../master.inc.php";
}

if (! $res && file_exists("../../../../main.inc.php")) {
    $res = @include("../../../../main.inc.php");
}

dol_include_once("/lyra/lib/lyra.lib.php");

// Security check.
if (empty($conf->lyra->enabled)) {
    accessforbidden('', 1, 1, 1);
}

$langs->load("main");
$langs->load("other");
$langs->load("dict");
$langs->load("lyra@lyra");
$langs->loadLangs(array("main", "other", "dict", "bills", "companies", "errors")); // File with generic data.

require_once DOL_DOCUMENT_ROOT . '/core/lib/company.lib.php';
require_once DOL_DOCUMENT_ROOT . '/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';
require_once DOL_DOCUMENT_ROOT . '/don/class/don.class.php';

dol_include_once('/lyra/core/modules/modLyra.class.php');
dol_include_once('/lyra/class/LyraResponse.php');
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";

global $conf, $notification_type;

$source = '';
$lyra_response = null;

// Get response data.
$data = $_REQUEST;

// Check answer type (REDIRECT/EMBEDDED).
if (GETPOSTISSET('signature')) {
    $notification_type = (GETPOSTISSET('vads_hash') ? 'IPN' : 'BACK_BUTTON');

    $lyra_response = new LyraResponse(
        $data,
        $conf->global->LYRA_CTX_MODE,
        $conf->global->LYRA_KEY_TEST,
        $conf->global->LYRA_KEY_PROD,
        modLyra::getDefault('SIGN_ALGO')
    );

    // Check the authenticity of the request.
    if (! $lyra_response->isAuthentified()) {
        lyra_syslog("Invalid signature with data: " . print_r($data, true), '', LOG_ERR);
        print_notification_message($data, $source, 'INVALID_SIGNATURE', ERROR_INVALID_SIGNATURE);
    }
} elseif (GETPOSTISSET('kr-hash')) {
    $notification_type = ((GETPOST('kr-hash-key') == 'password') ? 'IPN' : 'BACK_BUTTON');
    $key = ($notification_type === 'IPN') ? getLyraPrivateKey() : getLyraReturnKey();

    // Check the authenticity of the request.
    if (! modLyra::checkHash($_POST, $key)) {
        lyra_syslog("Invalid signature with data: " . print_r($_POST, true), '', LOG_ERR);
        print_notification_message($data, $source, 'INVALID_SIGNATURE', ERROR_INVALID_SIGNATURE);
    }

    $answer = json_decode($data['kr-answer'], true);
    if (is_array($answer)) {
        $data = modLyra::convertRestResult($answer);
        $lyra_response = new LyraResponse($data, null, null, null);
    }
} else {
    print_notification_message($data, $source, 'ERROR', ERROR_ANSWER_TYPE);
}

if ($notification_type !== 'IPN') {
    llxHeaderLyra($langs->trans("LYRA_PAYMENT_FORM"));
}

$amount = $lyra_response->get('amount');
$currency = LyraApi::findCurrencyByNumCode($lyra_response->get('currency'));

$order_id = $lyra_response->get('order_id');
$full_tag = $lyra_response->getExtInfo('full_tag');
$trans_status = $lyra_response->get('trans_status');

lyra_syslog("----------- NOTIFICATION TYPE = " . $notification_type . " -----------", $order_id, LOG_INFO);

// Get payment source (INVOICE, ORDER, FREE, DONATION...)
$source = getSourceType($full_tag);
lyra_syslog('source=' . $source . ' || Amount=' . $amount . ' || Order_id=' . $order_id . ' || status=' . $trans_status, $order_id);

if ($lyra_response->isAcceptedPayment()) {
    if ($lyra_response->isPendingPayment()) {
        if ($source === 'invoice') {
            $invoice = new Facture($db);
            $result = $invoice->fetch('', $order_id);

            if (! verifyAmount($source, $data)) {
                exit;
            } else {
                $alreadyPaid = verifyAlreadyPaid($source, $order_id);
                if (! $alreadyPaid) {
                    setStatusNotePrivate($invoice, $trans_status, $order_id);
                } else {
                    print_notification_message($data, $source, 'SUCCESS', SUCCESSFUL_PAYMENT);
                }
            }
        }

        print_notification_message($data, $source, 'PENDING', PENDING_STATUS);
        exit;
    }

    $error = 0;

    if ($source === 'free') {
        $ext_payment_id = $lyra_response->get('trans_uuid');
    }

    $alreadyPaid = verifyAlreadyPaid($source, $order_id, $ext_payment_id);
    if (! $alreadyPaid) { // If no record of the payment exists.
        if ($source === 'invoice' || $source === 'free' ) { // If it is a facture or a free amount.
            // If it is an invoice, consult the invoice by ref (order_id) and check the amount compared with signature amount.
            if ($source === 'invoice') {
                $invoice = new Facture($db);
                $result = $invoice->fetch('', $order_id);
                $id = $invoice->id;

                if (! verifyAmount($source, $data)) {
                    exit;
                }

               $note_private = $invoice->note_private;
               $key = '##';
               $pos = strripos($note_private, $key);
               if ($pos) {
                  $note_private = substr($note_private, $pos + 2);
               }

               $sql = "UPDATE " . MAIN_DB_PREFIX . "facture SET ";
               $sql .= " note_private='" . $note_private . "'";
               $sql .= " WHERE rowid = " . $id;
               $resql = $db->query($sql);
               if (! $resql) {
                  $error++;
               }
            }

            // If invoice is found or is a free amount that doesn't need to find an object.
            if ($result || $source === 'free') {
                $now = dol_now();
                $ipaddress = $lyra_response->get('ip_address');
                $FinalPaymentAmt = round($currency->convertAmountToFloat($amount), $currency->getDecimals());
                $transaction_uuid = $lyra_response->get('trans_uuid');
                $mode = $conf->global->LYRA_CTX_MODE;
                $service = (($mode === 'TEST') ? 'Lyra Test' : 'Lyra Production');

                // Register of new payment on llx_paiement.
                $db->begin();

                // Creation of payment line.
                $paiement = new Paiement($db);
                $paiement->datepaye = $now;
                $paiement->amounts = array($invoice->id => $FinalPaymentAmt); // All payments dispatching with invoice id.
                $paiement->multicurrency_amounts = array($invoice->id => $FinalPaymentAmt); // All payments dispatching.
                $paymentTypeId = 0;

                $paymentTypeId = $conf->global->LYRA_PAYMENT_MODE_FOR_PAYMENTS;
                if (empty($paymentTypeId)) {
                    $paymentType = $_SESSION["paymentType"];
                    if (empty($paymentType)) {
                        $paymentType = 'VAD';
                    }

                    $paymentTypeId = dol_getIdFromCode($db, $paymentType, 'c_paiement', 'code', 'id', 1);
                }

                $paiement->paiementid = $paymentTypeId;
                $paiement->note_public = 'Online payment ' . dol_print_date($now, 'standard') . ' from ' . $ipaddress;
                $paiement->ext_payment_id = $transaction_uuid;
                $paiement->ext_payment_site = $service;
                $paiement->num_payment = $order_id;

                if (! $error) {
                    if ($source === 'free') { // Only create record on llx_paiement for free amount payment.
                        $db->begin();

                        $refPayment = $paiement->getNextNumRef(is_object($thirdparty) ? $thirdparty : '');
                        $total = $FinalPaymentAmt;
                        $mtotal = $FinalPaymentAmt;
                        $num_payment = $paiement->num_payment;
                        $note = $paiement->note_public;

                        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "paiement (entity, ref, datec, datep, amount, multicurrency_amount, fk_paiement, num_paiement, note, ext_payment_id, ext_payment_site, fk_user_creat)";
                        $sql.= " VALUES (" . $conf->entity . ", '" . $db->escape($refPayment) . "', '" . $db->idate($now) . "', '" . $db->idate($paiement->datepaye ) . "', " . $total . ", " . $mtotal . ", " . $paiement->paiementid . ", '" . $db->escape($num_payment) . "', '" . $db->escape($note) . "', '" . $paiement->ext_payment_id . "', '" . $paiement->ext_payment_site . "', " . $user->id . ")";

                        $resql = $db->query($sql);
                        if ($resql) {
                            $paiement_id = $db->last_insert_id(MAIN_DB_PREFIX . 'paiement');
                            lyra_syslog("Record Payment Created", $order_id);
                        }
                    } else {
                        $paiement_id = $paiement->create($user, 1); // This include closing invoices and regenerating documents.
                    }

                    if ($paiement_id < 0) {
                        lyra_syslog($paiement->error . ' ' . join("<br>\n", $paiement->errors), $order_id, LOG_ERR);
                        $error++;
                    } else {
                        lyra_syslog("Record Payment Created", $order_id);
                    }
                }

                if (! $error && ! empty($conf->banque->enabled) && ! empty($conf->global->LYRA_DOLIBARR_BANK_ACCOUNT)) {
                    $bankaccountid = $conf->global->LYRA_DOLIBARR_BANK_ACCOUNT;
                    if ($bankaccountid > 0) {
                        $label='(CustomerInvoicePayment)';
                        if ($invoice->type == Facture::TYPE_CREDIT_NOTE) {
                            // Refund of a credit note.
                            $label='(CustomerInvoicePaymentBack)';
                        }

                        if ($source === 'free') {
                           $label='(FreePayment)';
                        }

                        $paiement->amount = $total;
                        $paiement->id = $paiement_id;
                        $result=$paiement->addPaymentToBank($user, 'payment', $label, $bankaccountid, '', '');

                        if ($result < 0) {
                            lyra_syslog($paiement->error . ' ' . join("<br>\n", $paiement->errors), $order_id, LOG_ERR);
                            $error++;
                        }
                    } else {
                        $postactionmessages[] = NOT_BANK_PAYMENT;
                        lyra_syslog(NOT_BANK_PAYMENT, $order_id, LOG_WARNING);
                    }
                }

                if (! $error) {
                    $db->query("COMMIT");
                    lyra_syslog('COMMIT', $order_id);
                } else {
                    $db->rollback();
                    lyra_syslog('ROLLBACK', $order_id, LOG_ERR);
                }
            }
        }

        if (! $error) {
            print_notification_message($data, $source, 'SUCCESS', SUCCESSFUL_PAYMENT, $postactionmessages);
            exit;
        } else {
            print_notification_message($data, $source, 'ERROR', ERROR_PAYMENT, $postactionmessages);
            exit;
        }
    } else {
        print_notification_message($data, $source, 'SUCCESS', SUCCESSFUL_PAYMENT, $postactionmessages);
        exit;
    }
} elseif ($lyra_response->isCancelledPayment()) {
    print_notification_message($data, $source, 'ERROR', CANCELLED_STATUS_TYPE . ' (' . $trans_status . ')');
} else {
    print_notification_message($data, $source, 'ERROR', ERROR_PAYMENT . ' (' . $trans_status . ')');
}

/**
 * Obtain source type (free, invoice, order, donation, contractline, membersubscription).
 *
 * @param  string   $order_info  Information from answer
 * @return string   $source       (free, invoice, order, donation, contractline, membersubscription)
 */
function getSourceType ($order_info)
{
    $source = '';
    if (preg_match('#^TAG(.*)$#', $order_info)) {
        $source = 'free'; // Free amount.
    } elseif (preg_match('#^INV(.*)$#', $order_info)) {
        $source = 'invoice'; // Invoice.
    } elseif (preg_match('#^ORD(.*)$#', $order_info)) {
        $source = 'order';
    } elseif (preg_match('#^ORD(.*)$#', $order_info)) {
        $source = 'order'; // Order.
    } elseif (preg_match('#^DON(.*)$#', $order_info)) {
        $source = 'donation'; // Donation.
    } elseif (preg_match('#^COL(.*)$#', $order_info)) {
        $source = 'contractline'; // Contract ligne.
    } elseif (preg_match('#^MEM(.*)$#', $order_info)) {
        $source = 'membersubscription'; // Member subscription.
    } else {
        $source = '';
    }

    return $source;
}

/**
 * Print result of payment according to status answer.
 */
function print_result_payment($data, $source, $resultType, $additionalMessages = array())
{
    global $conf, $mysoc, $langs, $notification_type;

    $suffix = '';
    $title = '';
    $print_button = true;

    if ($notification_type !== 'IPN') {
        // Show message.
        print '<span id="dolpaymentspan"></span>' . "\n";
        print '<div id="dolpaymentdiv" align="center">' . "\n";

        // Show logo.
        $width = 0;

        // Define logo and logosmall.
        $logosmall = $mysoc->logo_small;
        $logo=$mysoc->logo;
        $paramlogo = 'ONLINE_PAYMENT_LOGO_' . $suffix;

        if (! empty($conf->global->$paramlogo)) {
            $logosmall = $conf->global->$paramlogo;
        } elseif (! empty($conf->global->ONLINE_PAYMENT_LOGO)) {
            $logosmall = $conf->global->ONLINE_PAYMENT_LOGO;
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

    $amount = $data['vads_amount'];
    $order_id = $data['vads_order_id'];
    $status = $data['vads_trans_status'];

    if ($resultType === 'ERROR') {
        $url = getOnlinePaymentUrl(0, $source, $order_id, $amount, $order_id);
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
    } elseif ($resultType === 'SUCCESS') {
        if ($source === 'free' && $resultType === 'SUCCESS') {
            $print_button = false;
        }

        $url = getOnlinePaymentUrl(0, $source, $order_id);
        $message = $langs->trans("LYRA_THANK_FOR_PAYMENT_TEXT");
        $messageDesc = $langs->trans("LYRA_SUCCESS_PAYMENT_TEXT");
        $messageClic = $langs->trans("LYRA_DETAILS");
    } elseif ($resultType === 'PENDING') {
        $url = getOnlinePaymentUrl(0, $source, $order_id);
        $message = $langs->trans("LYRA_PENDING_PAYMENT");
        $messageDesc = $langs->trans("LYRA_PENDING_PAYMENT_DESC");
        $messageClic = $langs->trans("LYRA_DETAILS");
        if ($source === 'free') {
            $print_button = false;
        }
    } elseif ($resultType === 'INVALID_SIGNATURE') {
        $message = $langs->trans("LYRA_INVALID_SIGNATURE");
        $messageDesc = $langs->trans("LYRA_INVALID_SIGNATURE_DESC");
        $print_button = false;
    } elseif ($resultType === 'INVALID_AMOUNT') {
         $message = $langs->trans("LYRA_INVALID_AMOUNT");
         $messageDesc = $langs->trans("LYRA_INVALID_AMOUNT_DESC");
         $print_button = false;
    }

    // Output payment summary form.
    if ($notification_type !== 'IPN') {
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
        print_summary_payment_details($data, $source);
        print '<tr><td><br></td></tr>';

        print '<tr>';
        print '<td align="left" colspan="2">' . $messageDesc . '</td>';
        print '</tr>' . "\n";

        if ($resultType === 'SUCCESS') {
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
        if ($print_button) {
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
function print_summary_payment_details($data, $source)
{
    global $langs, $mysoc, $conf, $db;

    $var = false;
    $creditor = $mysoc->name;
    $currency = LyraApi::findCurrencyByAlphaCode($conf->currency);
    $amount = round($currency->convertAmountToFloat($data['vads_amount']), $currency->getDecimals());
    $order_id = $data['vads_order_id'];
    $fulltag = $data['vads_ext_info_full_tag'];
    $cust_name = $data['vads_cust_first_name'];

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
        print ' <b>' . $langs->trans("Currency" . $currency->getAlpha3()) . '</b>';
        print '</td></tr>' . "\n";

        // Tag.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_PAYMENT_CODE");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b style="word-break: break-all;">' . $fulltag . '</b>';
        print '</td></tr>' . "\n";
    }

    // Payment on customer order.
    if ($source == 'order') {
        $found = true;
        $langs->load("orders");

        require_once DOL_DOCUMENT_ROOT . '/commande/class/commande.class.php';

        $order = new Commande($db);
        $result = $order->fetch('', $order_id);
        if ($result <= 0) {
            $mesg = $order->error;
            $error++;
        } else {
            $result = $order->fetch_thirdparty($order->socid);
        }

        $object = $order;
        $fulltag = dol_string_unaccent($fulltag);

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
        print ' <b>' . $langs->trans("Currency" . $currency) . '</b>';
        print '</td></tr>' . "\n";

        // Tag.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_PAYMENT_CODE");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b style="word-break: break-all;">' . $fulltag . '</b>';
        print '</td></tr>' . "\n";
    }

    // Payment of customer invoice.
    if ($source == 'invoice') {
        $found = true;
        $langs->load("bills");

        require_once DOL_DOCUMENT_ROOT . '/compta/facture/class/facture.class.php';

        $invoice = new Facture($db);
        $result = $invoice->fetch('', $order_id);
        if ($result <= 0) {
            $mesg = $invoice->error;
            $error++;
        } else {
            $result = $invoice->fetch_thirdparty($invoice->socid);
        }

        $object = $invoice;
        $fulltag = dol_string_unaccent($fulltag);

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
        print ' <b>' . $langs->trans("Currency" . $currency->getAlpha3()) . '</b>';
        print '</td></tr>' . "\n";

        // Tag.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_PAYMENT_CODE");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b style="word-break: break-all;">' . $fulltag . '</b>';
        print '</td></tr>' . "\n";
    }

    // Payment on contract line.
    if ($source == 'contractline') {
        $found = true;
        $langs->load("contracts");

        require_once DOL_DOCUMENT_ROOT . '/contrat/class/contrat.class.php';

        $contract = new Contrat($db);
        $contractline = new ContratLigne($db);
        $result = $contractline->fetch('', $order_id);

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
            if ($contractline->fk_product && ! empty($conf->global->PAYMENT_USE_NEW_PRICE_FOR_CONTRACTLINES)) {
                $product = new Product($db);
                $result = $product->fetch($contractline->fk_product);

                // We define price for product (TODO Put this in a method in product class).
                if (! empty($conf->global->PRODUIT_MULTIPRICES)) {
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
           $fulltag = GETPOST('fulltag', 'alpha');
        } else {
            $fulltag = 'COL=' . $contractline->id . '.CON=' . $contract->id . '.CUS=' . $contract->thirdparty->id . '.DAT=' . dol_print_date(dol_now(), '%Y%m%d%H%M%S');
            if (! empty($TAG)) {
                $tag = $TAG;
                $fulltag .= '.TAG=' . $TAG;
            }
        }

        $fulltag = dol_string_unaccent($fulltag);

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
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b>' . $cust_name . '</b>';
        print '</td></tr>' . "\n";

        // Object.
        $text = '<b>' . $langs->trans("PaymentRenewContractId", $order_id, $contractline->ref) . '</b>';
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
        if ($contractline->fk_product) {
            if ($contractline->product->isService() && $contractline->product->duration_value > 0) {
                $label = $langs->trans("Duration");

                if ($contractline->product->duration_value > 1) {
                    $dur = array("h"=>$langs->trans("Hours"), "d"=>$langs->trans("DurationDays"), "w"=>$langs->trans("DurationWeeks"), "m"=>$langs->trans("DurationMonths"), "y"=>$langs->trans("DurationYears"));
                } else {
                    $dur = array("h"=>$langs->trans("Hour"), "d"=>$langs->trans("DurationDay"), "w"=>$langs->trans("DurationWeek"), "m"=>$langs->trans("DurationMonth"), "y"=>$langs->trans("DurationYear"));
                }

                $duration = $contractline->product->duration_value . ' ' . $dur[$contractline->product->duration_unit];
            }
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
        print ' <b>' . $langs->trans("Currency" . $currency) . '</b>';
        print '<input type="hidden" name="currency" value="' . $currency . '">';
        print '</td></tr>' . "\n";

        // Tag.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_PAYMENT_CODE");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b style="word-break: break-all;">' . $fulltag . '</b>';
        print '</td></tr>' . "\n";
    }

    // Payment on member subscription.
    if ($source == 'membersubscription') {
        $found = true;
        $langs->load("members");

        require_once DOL_DOCUMENT_ROOT . '/adherents/class/adherent.class.php';
        require_once DOL_DOCUMENT_ROOT . '/adherents/class/subscription.class.php';

        $member = new Adherent($db);
        $result = $member->fetch('', $order_id);
        if ($result <= 0) {
            $mesg = $member->error;
            $error++;
        } else {
            $member->fetch_thirdparty();
            $subscription = new Subscription($db);
        }

        $object = $member;
        $fulltag = dol_string_unaccent($fulltag);

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
            if (empty($conf->global->MEMBER_NEWFORM_AMOUNT)) {
                print ' (' . $langs->trans("LYRA_TO_COMPLETE");
            }

            if (! empty($conf->global->MEMBER_EXT_URL_SUBSCRIPTION_INFO)) {
                print ' - <a href="' . $conf->global->MEMBER_EXT_URL_SUBSCRIPTION_INFO . '" rel="external" target="_blank">' . $langs->trans("SeeHere") . '</a>';
            }

            if (empty($conf->global->MEMBER_NEWFORM_AMOUNT)) {
                print ')';
            }
        }

        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">';
        $valtoshow = '';
        if (empty($amount) || ! is_numeric($amount)) {
            $valtoshow = price2num(GETPOST("newamount", 'alpha'), 'MT');
            // Force default subscription amount to value defined into constant...
            if (empty($valtoshow)) {
                if (! empty($conf->global->MEMBER_NEWFORM_EDITAMOUNT)) {
                    if (! empty($conf->global->MEMBER_NEWFORM_AMOUNT)) {
                        $valtoshow = $conf->global->MEMBER_NEWFORM_AMOUNT;
                    }
                } else {
                    if (! empty($conf->global->MEMBER_NEWFORM_AMOUNT)) {
                        $amount = $conf->global->MEMBER_NEWFORM_AMOUNT;
                    }
                }
            }
        }

        if (empty($amount) || ! is_numeric($amount)) {
            if (! empty($conf->global->MEMBER_MIN_AMOUNT) && $valtoshow) {
                $valtoshow = max($conf->global->MEMBER_MIN_AMOUNT, $valtoshow);
            }

            print '<input class="flat maxwidth75" type="text" name="newamount" value="' . $valtoshow . '">';
        } else {
            $valtoshow = $amount;
            if (! empty($conf->global->MEMBER_MIN_AMOUNT) && $valtoshow) {
                $valtoshow = max($conf->global->MEMBER_MIN_AMOUNT, $valtoshow);
            }

            print '<b>' . price($valtoshow) . '</b>';
        }

        // Currency.
        print ' <b>' . $langs->trans("Currency" . $currency) . '</b>';
        print '</td></tr>' . "\n";

        // Tag.
        print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_PAYMENT_CODE");
        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b style="word-break: break-all;">' . $fulltag . '</b>';
        print '</td></tr>' . "\n";
    }

    // Payment on donation.
    if ($source == 'donation') {
        $found = true;
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

        $object = $don;
        $fulltag = dol_string_unaccent($fulltag);

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
             if (empty($conf->global->MEMBER_NEWFORM_AMOUNT)) {
                 print ' (' . $langs->trans("LYRA_TO_COMPLETE");
             }

             if (! empty($conf->global->MEMBER_EXT_URL_SUBSCRIPTION_INFO)) {
                 print ' - <a href="' . $conf->global->MEMBER_EXT_URL_SUBSCRIPTION_INFO . '" rel="external" target="_blank">' . $langs->trans("SeeHere") . '</a>';
             }

             if (empty($conf->global->MEMBER_NEWFORM_AMOUNT)) {
                 print ')';
            }
        }

        print '</td><td class="CTableRow' . ($var ? '1' : '2') . '">';
        $valtoshow = '';
        if (empty($amount) || ! is_numeric($amount)) {
            $valtoshow = price2num(GETPOST("newamount", 'alpha'), 'MT');
            // Force default subscription amount to value defined into constant...
            if (empty($valtoshow)) {
                if (! empty($conf->global->MEMBER_NEWFORM_EDITAMOUNT)) {
                    if (! empty($conf->global->MEMBER_NEWFORM_AMOUNT)) {
                        $valtoshow = $conf->global->MEMBER_NEWFORM_AMOUNT;
                    }
                } else {
                    if (! empty($conf->global->MEMBER_NEWFORM_AMOUNT)) {
                        $amount = $conf->global->MEMBER_NEWFORM_AMOUNT;
                    }
                }
            }
        }

        if (empty($amount) || ! is_numeric($amount)) {
            if (! empty($conf->global->MEMBER_MIN_AMOUNT) && $valtoshow) {
                $valtoshow = max($conf->global->MEMBER_MIN_AMOUNT, $valtoshow);
            }

            print '<input class="flat maxwidth75" type="text" name="newamount" value="' . $valtoshow . '">';
        } else {
            $valtoshow = $amount;
            if (! empty($conf->global->MEMBER_MIN_AMOUNT) && $valtoshow) {
                $valtoshow = max($conf->global->MEMBER_MIN_AMOUNT, $valtoshow);
            }

            print '<b>' . price($valtoshow) . '</b>';
         }

         // Currency.
         print ' <b>' . $langs->trans("Currency" . $currency) . '</b>';
         print '</td></tr>' . "\n";

         // Tag.
         print '<tr class="CTableRow' . ($var ? '1' : '2') . '"><td class="CTableRow' . ($var ? '1' : '2') . '">' . $langs->trans("LYRA_PAYMENT_CODE");
         print '</td><td class="CTableRow' . ($var ? '1' : '2') . '"><b style="word-break: break-all;">' . $fulltag . '</b>';
         print '</td></tr>' . "\n";
    }
}

/**
 * Verify if source is already paid.
 */
function verifyAlreadyPaid($source, $ref, $ext_payment_id = '')
{
    global $conf, $db, $langs;

    if ($source == 'invoice') {
        $object = new Facture($db);
    } elseif ($source == 'order') {
        $object = new Commande($db);
    } elseif ($source == 'donation') {
        $object = new Don($db);
    } elseif ($source == 'membersubscription') {
        $object = null;
    } elseif ($source == 'contractline') {
         $object = new ContratLigne($db);
    } elseif ($source == 'free') {
        $object = new Paiement($db);
        $sql = 'SELECT p.rowid, p.ref, p.ext_payment_id, p.ext_payment_site, p.fk_bank,';
        $sql.= ' p.num_paiement as num_payment, p.note';
        $sql.= ' FROM ' . MAIN_DB_PREFIX . 'paiement as p LEFT JOIN ' . MAIN_DB_PREFIX . 'c_paiement as c ON p.fk_paiement = c.id';
        $sql.= ' WHERE p.entity IN (' . getEntity('invoice') . ')';
        if ($ref) {
            $sql.= " and p.num_paiement = '" . $ref . "'";
        }

        if ($ext_payment_id) {
            $sql.= " and p.ext_payment_id  = '" . $ext_payment_id . "'";
        }

        $num_rows = $db->query($sql)->num_rows;
        if ($num_rows > 0) {
            return true;
        }
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
            if ($source == 'order' && $object->billed) {
                return true;
            } elseif ($source == 'invoice' && $object->paye == 1) {
                return true;
            } elseif ($source == 'donation' && $object->paid) {
                return true;
            } elseif ($source == 'membersubscription' && $object->datefin > dol_now()) {
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
    global $db, $langs, $notification_type;

    $order_id = $data['vads_order_id'];
    $currency = LyraApi::findCurrencyByNumCode($data['vads_currency']);
    $amount = round($currency->convertAmountToFloat($data['vads_amount']), $currency->getDecimals());

    if ($source === 'invoice') {
        $invoice = new Facture($db);
        $invoice->fetch('', $order_id);

        $factureAmount = (float) $invoice->total_ttc;

        if ($factureAmount !== (float) $amount) {
            $alreadyPaid = verifyAlreadyPaid($source, $order_id);
            if (! $alreadyPaid) {
               setStatusNotePrivate($invoice, 'INVALID_AMOUNT', $order_id);
            } else { // If you want to notify already payment intead of invalid amount activate this code.
                print_notification_message($data, $source, 'SUCCESS', SUCCESSFUL_ALREADY_PAYMENT);
            }

            if ($notification_type !== 'IPN') {
                $additionalInfo = array($langs->trans("LYRA_IPN_AMOUNT", $amount), $langs->trans("LYRA_INVOICE_AMOUNT", strval($factureAmount)));
            }

            print_notification_message($data, $source, 'INVALID_AMOUNT', ERROR_INVALID_AMOUNT . '. INVOICE AMOUNT = ' . strval($factureAmount) . '; IPN AMOUNT VALUE = ' . $amount, $additionalInfo);
            return false;
        }

        return true;
    }

    return true;
}

/**
 * Set field private_note on database with the status from answer IPN.
 */
function setStatusNotePrivate($invoice, $trans_status, $order_id)
{
    global $db;

    $error = 0;
    $id = $invoice->id;
    $db->begin();

    $note_private = $invoice->note_private; // Example '##STATUS=WAITING_AUTHORISATION## Test Private Note New Facture'.
    $key   = '##';

    // If exists another pending status is eliminated.
    $pos = strripos($note_private, $key); // The last appearance is found.
    if ($pos) {
        $note_private = substr($note_private, $pos + 2);
    }

    // Generate and replace new status.
    $key = '##';
    $note_private = $key . "STATUS=" . $trans_status . $key . $note_private;

    $sql = "UPDATE " . MAIN_DB_PREFIX . "facture SET ";
    $sql .= " note_private='" . $note_private . "'";
    $sql .= " WHERE rowid = " . $id;

    $resql = $db->query($sql);
    if (! $resql) {
        $error++;
    }

    if (! $error) {
        $db->commit();
        lyra_syslog("Set Private Note = " . $trans_status, $order_id);
    } else {
        $db->rollback();
        lyra_syslog("Not Set Private Note = " . $trans_status, $order_id);
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
function print_notification_message($data, $source, $messageType, $supportMessage, $additionalInfo = array(), $verify_context = 0)
{
    global $notification_type, $conf;

    $ctx_mode = $conf->global->LYRA_CTX_MODE;
    if ($verify_context == 0) {
        $ctx_mode = 'PRODUCTION';
    }

    $supportMessage = 'DOLIBARR - ' . $supportMessage;
    if ($additionalInfo) {
        foreach($additionalInfo as $msg) {
            $supportMessage .= ' || ' . $msg;
        }
    }

    if ($messageType === 'ERROR' || $messageType === 'INVALID_SIGNATURE' || $messageType === 'INVALID_AMOUNT' ) {
        $supportMessage .= ' || KO';
        $log = LOG_ERR;
    } else {
        $supportMessage .= ' || OK';
        $log = LOG_INFO;
    }

    lyra_syslog($supportMessage, $data['vads_order_id'], $log);
    if ($notification_type !== 'IPN') {
        if ($ctx_mode === 'PRODUCTION') {
            print_result_payment($data, $source, $messageType, $additionalInfo);
            exit;
        } else {
            throw new Exception($supportMessage);
        }
    } else {
        print $supportMessage;
        exit;
    }
}