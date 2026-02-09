<?php
/**
 * Copyright © Lyra Network.
 * This file is part of Lyra Collect plugin for Dolibarr. See COPYING.md for license details.
 *
 * @author    Lyra Network (https://www.lyra.com/)
 * @copyright Lyra Network
 * @license   http://www.gnu.org/licenses/gpl.html GNU General Public License (GPL v3)
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';
dol_include_once('/lyra/lib/lyra.lib.php');

/**
 * Class ActionsLyra
 */
class ActionsLyra extends CommonHookActions
{
    /**
     * @var DoliDB database handler.
     */
    public $db;

    /**
     * @var string error code (or message).
     */
    public $error = '';

    /**
     * @var array errors.
     */
    public $errors = array();

    /**
     * @var array Hook results. Propagated to $hookmanager->resArray for later reuse.
     */
    public $results = array();

    /**
     * @var string displayed by executeHook() immediately after return.
     */
    public $resprints;

    /**
     * Constructor.
     *
     *  @param    DoliDB        $db      Database handler
     */
    public function __construct($db)
    {
        global $langs;

        $this->db = $db;
        $langs->load("lyra@lyra");
    }

    /**
     * Execute action.
     *
     * @param    array           $parameters     Array of parameters
     * @param    CommonObject    $object         The object to process (an invoice if you are in invoice module, a propale in propale's module, etc...)
     * @param    string          $action         'add', 'update', 'view'
     * @return   int                             <0 if KO,
     *                                           =0 if OK but we want to process standard actions too,
     *                                           >0 if OK and we want to replace standard actions
     */
    public function getNomUrl($parameters, &$object, &$action)
    {
        $this->resprints = '';

        return 0;
    }

    /**
     * Print Lyra Payment Button on screen.
     *
     * @param   array            $parameters     Array of parameters
     * @param   Object            $object         Object output
     * @param   string            $action         'add', 'update', 'view'
     * @param   HookManager       $hookmanager    Hook manager propagated to allow calling another hook
     * @return  int                               < 0 on error, 0 on success, 1 to replace standard code
     */
    public function doAddButton($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $langs, $conf;

        $this->error = 0; // Error counter.
        $source = GETPOST("s", 'alpha') ? GETPOST("s", 'alpha') : GETPOST("source", 'alpha');

        if (in_array($parameters['currentcontext'], array('newpayment'))) { // Do something only for the context 'somecontext1' or 'somecontext2'.
            $paymentMethod = $parameters['paymentmethod'];

            if (isModEnabled('lyra') && ! lyraCanUseCurrency() && (getDolGlobalString('LYRA_CTX_MODE') == 'TEST' || GETPOST('forcesandbox', 'int'))) {
                dol_htmloutput_mesg($langs->trans('LYRA_UNSUPPORTED_CURRENCY_MESSAGE'), '', 'warning');
            } elseif ((empty($paymentMethod) || $paymentMethod == 'lyra') && isModEnabled('lyra') && lyraCanUseCurrency()) {
                if (getDolGlobalString('LYRA_CTX_MODE') == 'TEST' || GETPOST('forcesandbox', 'int')) {
                    dol_htmloutput_mesg($langs->trans('LYRA_SANDBOX_MESSAGE'), '', 'warning');
                }

                if (! isModEnabled('bank') || empty(getDolGlobalString('LYRA_DOLIBARR_BANK_ACCOUNT')) || (getDolGlobalString('LYRA_DOLIBARR_BANK_ACCOUNT') == -1)) {
                    dol_htmloutput_mesg($langs->trans('LYRA_WARNING_BANK'), '', 'warning');
                }

                if ($source === 'invoice' || ! $source) {
                    $result = '<br>';
                    $result .= '<div class="button buttonpayment" id="div_dopayment_lyra">';
                    $result .= '<span class="fa fa-credit-card"></span>';
                    $result .= '<input class="" type="submit" id="dopayment_lyra" name="dopayment_lyra" value="' . $langs->trans("LYRA_PAY_BUTTON") . '">';
                    $result .= '<br>';
                    $result .= '<span class="buttonpaymentsmall">' . $langs->trans("LYRA_PAY_BUTTON_MESSAGE") . '</span>';
                    $result .= '</div>';

                    $script = '<script>
                                   $(document).ready(function() {
                                       $("#div_dopayment_lyra").click(function() {
                                           $("#dopayment_lyra").click();
                                       });

                                       $("#dopayment_lyra").click(function(e) {
                                           $("#div_dopayment_lyra").css( \'cursor\', \'wait\' );
                                           e.stopPropagation();

                                           return true;
                                       });
                                   });
                               </script>';

                    if (lyraEmbeddedPayment() && getDolGlobalString('LYRA_POPIN_MODE')) {
                        $ref = GETPOST('ref');
                        $invoice = new Facture($db);
                        $fetchResult = $invoice->fetch(0, $ref);
                        if ($fetchResult <= 0) {
                            $this->errors[] = $invoice->error;
                            $this->errors[]++;
                        } else {
                            $fetchResult = $invoice->fetch_thirdparty($invoice->socid);
                        }

                        $amount = price2num($invoice->total_ttc - ($invoice->getSommePaiement() + $invoice->getSumCreditNotesUsed() + $invoice->getSumDepositsUsed()));
                        if (GETPOST("amount", 'alpha')) {
                            $amount = GETPOST("amount", 'alpha');
                        }

                        $amount = price2num($amount);

                        if (! GETPOST("currency", 'alpha')) {
                            $currency = $conf->currency;
                        } else {
                            $currency = GETPOST("currency", 'aZ09');
                        }

                        $params = [
                            'paymentmethod' => 'lyra',
                            'amount' => $amount,
                            'currency' => $currency,
                            'tag' => GETPOST("tag", 'alpha'),
                            'lyrapopin' => 1,
                            'dopayment' => GETPOST('dopayment', 'alpha')
                        ];

                        // @phan-suppress-next-line PhanTypeMismatchArgumentNullable
                        $reshook = $hookmanager->executeHooks('doPayment', $params, $object, $action);
                        if ($reshook >= 0) {
                            print $hookmanager->resPrint;

                            $script = '<script>
                                           $(document).ready(function() {
                                               $("#div_dopayment_lyra").click(function(e) {
                                                   $("#dolpaymenttable").show();
                                                   KR.openPopin();
                                                   e.stopPropagation();

                                                   return false;
                                               });
                                           });
                                       </script>';
                        }
                    }

                    $result .= $script;

                    $this->resprints = $result;
                }

                return $this->resprints;
            }
        }

        if (! $this->error) {
            return 0;
        }

        return -1;
    }

    /**
     * Set Lyra as a valid payment.
     *
     * @param   array            $parameters   Array of parameters
     * @param   Object           $object       Object output
     * @param   string           $action       'add', 'update', 'view'
     * @param   HookManager      $hookmanager  Hook manager propagated to allow calling another hook
     * @return  int                            < 0 on error, 0 on success, 1 to replace standard code
     */
    public function getValidPayment($parameters, &$object, &$action, $hookmanager)
    {
        global $langs;

        $paymentMethod = $parameters['paymentmethod'];
        if ((empty($paymentMethod) || $paymentMethod == 'lyra') && isModEnabled('lyra')
            && (lyraCanUseCurrency() || getDolGlobalString('LYRA_CTX_MODE') == 'TEST' || GETPOST('forcesandbox', 'int'))) {
            $langs->load("lyra");
            $parameters['validpaymentmethod']['lyra'] = 'valid';
        }

        return 0;
    }

    /**
     * Check status of $object (invoice, order, donation...) to show a message in newpayment.php.
     *
     * @param   array             $parameters   Array of parameters
     * @param   Object            $object       Object output
     * @param   string            $action       'add', 'update', 'view'
     * @param   HookManager       $hookmanager  Hook manager propagated to allow calling another hook
     * @return  int                             < 0 on error, 0 on success, 1 to replace standard code
     */
    public function doCheckStatus($parameters, &$object, &$action, $hookmanager)
    {
        global $langs;

        $this->error = 0; // Error counter.
        if (in_array($parameters['currentcontext'], array('newpayment'))) {
            $paymentMethod = isset($parameters['paymentmethod']) ? $parameters['paymentmethod'] : '';
            $source = $parameters['source'];
            $object = $parameters['object'];

            if ((empty($paymentMethod) || $paymentMethod == 'lyra') && isModEnabled('lyra')) {
                if ($source == 'order' && $object->billed) {
                    print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("OrderBilled") . '</span>';
                }

                if ($source == 'invoice' && strripos($object->note_private, '##')) { // The last appearance is found.
                    print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("LYRA_PENDING_PAYMENT_MSG") . '</span>';
                    exit;
                }

                if ($source == 'donation' && $object->paid) {
                    print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("DonationPaid") . '</span>';
                }
            }
        }

        if (! $this->error) {
            return 0;
        }

        $this->errors[] = 'Error message';

        return -1;
    }

    /**
     * Start payment process with Lyra.
     *
     * @param   array        $parameters    Array of parameters
     * @param   Object       $object        Object output
     * @param   string       $action        'add', 'update', 'view'
     * @param   HookManager  $hookmanager   Hook manager propagated to allow calling another hook
     * @return  int                        < 0 on error, 0 on success, 1 to replace standard code
     */
    public function doPayment($parameters, &$object, &$action, $hookmanager)
    {
        dol_include_once('/lyra/core/modules/modLyra.class.php');

        if (in_array($parameters['currentcontext'], array('newpayment')) && isset($parameters['dopayment'])
            && ($parameters['paymentmethod'] == 'lyra')) {
            $tag = (empty($parameters['tag']) ? GETPOST("ref", 'alpha') : $parameters['tag']);

            try {
                if (lyraEmbeddedPayment()) {
                    lyraEmbeddedForm($tag, getDolGlobalString('LYRA_POPIN_MODE'), $parameters['amount']);
                } else {
                    lyraRedirectForm($tag);
                }
            } catch (Exception $e) {
                if (! isset($parameters['lyrapopin'])) {
                    print '<div id="lyra_error_message" class="center" style="margin-bottom: 25px; width: 100%; padding: 12px 0px 12px 25px; font-size: 1.3rem;">
                        <span class="error">' .  $e->getMessage() . '</span>
                    </div>';
                }

                return -1;
            }

            return 0;
        }

        return -1;
    }
}