# Lyra Collect for Dolibarr

Lyra Collect for Dolibarr is an open source payment plugin developed by [Lyra Network](https://www.lyra.com/) for Dolibarr ERP. It allows you to manage your organization payment activities.

## Installation & Upgarde

If a previous version of the Lyra Collect module is installed on your Dolibarr ERP, you must uninstall it before adding. Connect to your FTP server and delete the plugin files and directories.

**Do not forget to backup your module settings.**

To install the new module version, unzip downloaded archive and copy the folder contents to your Dolibarr installation folder in `dolibarr/htdocs`.

## Configure newpayment.php
**Skip this configuration for version 13.0 or higher of Dolibarr.**

The file  __newpayment.php__  found in folder __temp_to_replace__, contains the calls to the hooks necessary to make payments with Lyra.

The following code should be replaced in  __newpayment.php__  file located in `dolibarr/htdocs/public/payment`

> Check if it is possible replace full newpayment.php file.

```
// Hook added by Lyra.
include_once DOL_DOCUMENT_ROOT . '/core/class/hookmanager.class.php';
$hookmanager = new HookManager($db);
$hookmanager->initHooks(array('newpayment'));
```

```
// This hook is used to push to $validpaymentmethod the Lyra payment method as valid.
$parameters = ['paymentmethod' => $paymentmethod, 'validpaymentmethod' => &$validpaymentmethod];
$reshook = $hookmanager->executeHooks('doValidatePayment', $parameters, $object, $action);
```

```
// Check status of the object (Invoice) to verify if it is paid.
$parameters = ['source' => $source, 'object' => $object];
$reshook = $hookmanager->executeHooks('doCheckStatus', $parameters, $object, $action);
```

```
// This hook is used to add Lyra button to newpayment.php.
$parameters = ['paymentmethod' => $paymentmethod];
$reshook = $hookmanager->executeHooks('doAddButton', $parameters, $object, $action);
```

```
// This hook is used to show the embedded form to make payments.
$parameters = [
			'paymentmethod'  => $paymentmethod,
			'amount'         => price2num(GETPOST("newamount"), 'MT'),
			'tag'            => GETPOST("tag", 'alpha'),
			'dopayment_lyra' => GETPOST('dopayment_lyra', 'alpha')
		];
$reshook = $hookmanager->executeHooks('doPayment', $parameters, $object, $action);
```

## Activation

In Dolibarr administration interface:
- In the left side panel, browse to `Setup > Modules/Applications`.
- Go to `Interfaces with external systems` section.
- Click on `Enable/Disable` button corresponding to the `Lyra Module` entry to activate it.

Open module configuration by clicking the `setup` icon in the module row, and configure your parameters and credentials from your Lyra Expert Back Office.

## License

Each Lyra Collect payment module for Dolibarr source file included in this distribution is licensed under the GNU General Public License (GPL 3.0 or later).

Please see LICENSE.txt for the full text of the GPL 3.0 license. It is also available through the world-wide-web at this URL: http://www.gnu.org/licenses/gpl.html.