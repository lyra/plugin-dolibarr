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
 * This file contains all methods to be executed as hooks.
 */

$langs->load("lyra@lyra");

/**
 * Class ActionsLyra
 */
class ActionsLyra
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
        $this->db = $db;
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
        global $conf, $langs;

        $error = 0; // Error counter.
        $source = GETPOST("s", 'alpha') ? GETPOST("s", 'alpha') : GETPOST("source", 'alpha');

        if (in_array($parameters['currentcontext'], array('newpayment'))) { // Do something only for the context 'somecontext1' or 'somecontext2'.
            $paymentmethod = $parameters['paymentmethod'];

            if ((empty($paymentmethod) || $paymentmethod == 'lyra') && ! empty($conf->lyra->enabled)) {
                if ($conf->global->LYRA_CTX_MODE == 'TEST' || GETPOST('forcesandbox', 'int')) { // We can force sandbox with param 'forcesandbox'.
                    dol_htmloutput_mesg($langs->trans('LYRA_SANDBOX_MESSAGE'), '', 'warning');
                }

                if (empty($conf->banque->enabled) || empty($conf->global->LYRA_DOLIBARR_BANK_ACCOUNT) || ($conf->global->LYRA_DOLIBARR_BANK_ACCOUNT == -1)) {
                    dol_htmloutput_mesg($langs->trans('LYRA_WARNING_BANK'), '', 'warning');
                }

                if ($source === 'invoice' || ! $source) {
                    $result =  '<br>';
                    $result .= '<div class="button buttonpayment" id="div_dopayment_lyra">';
                    $result .= '<span class="fa fa-credit-card"></span>';
                    $result .= '<input class="" type="submit" id="dopayment_lyra" name="dopayment_lyra" value="' . $langs->trans("LYRA_PAY_BUTTON") . '">';
                    $result .= '<br>';
                    $result .= '<span class="buttonpaymentsmall">' . $langs->trans("LYRA_PAY_BUTTON_MESSAGE") . '</span>';
                    $result .= '</div>';
                    $result .= '<script>
                                    $( document ).ready(function() {
                                        $("#div_dopayment_lyra").click(function() {
                                            $("#dopayment_lyra").click();
                                        });
                                        $("#dopayment_lyra").click(function(e) {
                                            $("#div_dopayment_lyra").css( \'cursor\', \'wait\' );
                                            e.stopPropagation();
                                            return true;
                                        });
                                    });
                                </script>
                                ';
                    print $result;
                    $this->resprints = $result;
                }

                return $this->resprints;
            }
        }

        if (! $error) {
            return 0; // Or return 1 to replace standard code.
        }

        $this->errors[] = 'Error message';
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
    public function doValidatePayment($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $langs;

        if (in_array($parameters['currentcontext'], array('newpayment'))) { // Do something only for the context 'somecontext1' or 'somecontext2'.
            $paymentmethod = $parameters['paymentmethod'];
            if ((empty($paymentmethod) || $paymentmethod == 'lyra') && ! empty($conf->lyra->enabled)) {
                $langs->load("lyra");
                $parameters['validpaymentmethod']['lyra'] = 'valid';
            }
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
        global $conf, $langs;

        $error = 0; // Error counter.
        if (in_array($parameters['currentcontext'], array('newpayment'))) { // Do something only for the context 'somecontext1' or 'somecontext2'.
            $paymentmethod = $parameters['paymentmethod'];
            $source = $parameters['source'];
            $object = $parameters['object'];
            if ((empty($paymentmethod) || $paymentmethod == 'lyra') && ! empty($conf->lyra->enabled)) {
                if ($source == 'order' && $object->billed) {
                    print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("OrderBilledPending") . '</span>';
                }

                if ($source == 'invoice' && strripos($object->note_private, '##')) { // The last appearance is found.
                    print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("LYRA_PENDING_PAYMENT_DESC") . '</span>';
                    exit;
                }

                if ($source == 'donation' && $object->paid) {
                    print '<br><br><span class="amountpaymentcomplete">' . $langs->trans("DonationPaidPending") . '</span>';
                }
            }
        }

        if (! $error) {
            return 0; // Or return 1 to replace standard code.
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
        dol_include_once('/lyra/lib/lyra.lib.php');
        dol_include_once('/lyra/core/modules/modLyra.class.php');

        global $conf;

        if (in_array($parameters['currentcontext'], array('newpayment'))) {
            if ($parameters['paymentmethod'] == 'lyra') {
                    $tag = (empty($parameters['tag']) ? GETPOST("ref", 'alpha') : $parameters['tag']);

                    lyra_syslog("----------- Type Form " . $conf->global->LYRA_CARD_INFO_MODE . " -----------", $tag, LOG_INFO);

                    if ($conf->global->LYRA_CARD_INFO_MODE == modLyra::MODE_FORM) {
                        lyraRedirectForm($tag);
                    } elseif (($conf->global->LYRA_CARD_INFO_MODE == modLyra::MODE_EMBEDDED) || ($conf->global->LYRA_CARD_INFO_MODE ==  modLyra::MODE_POPIN)) {
                        $pop_in = (($conf->global->LYRA_CARD_INFO_MODE == modLyra::MODE_EMBEDDED) ? 0 : 1);
                        lyraEmbeddedForm($tag, $pop_in);
                    }

                    return 0;
                }
        }

        $this->errors[] = 'Error message';
        return -1;
    }
}
