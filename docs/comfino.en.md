## DOWNLOAD PLUGIN

Open page https://github.com/comfino/Magento-2.3/releases and download latest plugin.

## INSTALLATION

Magento 2.3.5+
-------

**It is recommended to make a complete backup of the Magento directory before installing the module.**

After downloading the comfino.zip archive and unpacking, move the contents of the *comfino* directory to the Magento modules directory.

Target location: *magento/app/code/Comfino/ComfinoGateway*

where:

* *magento* - the name of the base directory of the store (may be different than in the above example)
* *ComfinoPay*, *ComfinoGateway* - subdirectories that should be created in the directory *app/code*

![Installation](images/dir_structure1.png "Installation")
![Installation](images/dir_structure2.png "Installation")

After copying the files, run the command below to check if the new module has been recognized:

`./bin/magento module:status`

The executed command should return a response similar to the following:

![Installation](images/magento_console1.png "Installation")

The *Comfino_ComfinoGateway* module should be present on the list of inactive modules.

![Installation](images/magento_console2.png "Installation")

In order to activate the module, execute the following commands:

* `./bin/magento module:enable Comfino_ComfinoGateway --clear-static-content`
* `./bin/magento setup:upgrade`
* `./bin/magento setup:di:compile`
* `./bin/magento setup:static-content:deploy -f`
* `./bin/magento cache:flush`

After completing the above steps, log in to the Magento admin panel to configure the installed module.

## CONFIGURATION

To access the module settings, follow the steps below:

![Configuration](images/en/menu1.png "Configuration")
![Configuration](images/en/menu2.png "Configuration")
![Configuration](images/en/menu3.png "Configuration")
![Configuration](images/en/menu4.png "Configuration")

## CONFIGURATION SECTIONS

![Configuration](images/en/mod_config1.png "Configuration")

The configuration parameters of the module are grouped by categories corresponding to the sections of the configuration panel: `"Payment settings"`, `"Widget"`, `"For developers"`.
The last section `"Plugin diagnostics"` does not contain any parameters to be set and has an information-diagnostic function. Among other things, it contains a local error log.

Before using ComfinoPay payments, the module must be configured.

The most important field that must be filled in is the "API Key" field from the `"Payment settings"` section.
In the `"For developers"` section, there is a second key field - `"Test environment API key"`.

Configuration parameter fields:

**Payment settings**

* **Enabled** — activity status of the ComfinoPay module (visibility on the list of payment methods in the basket)
* **Title** — text displayed on the list of payment methods (default `"ComfinoPay"`)
* **Minimal order value** — the value of the cart from which ComfinoPay payment is available (default: 30 PLN)
* **Restrict payment method to specific countries** — countries for which ComfinoPay payment is available (default: no restrictions)
* **API key** — a unique access key that allows the module to communicate with the ComfinoPay API (you will receive it from a ComfinoPay representative)

![Configuration](images/en/mod_config2.png "Configuration")

**Widget**

![Configuration](images/en/mod_config3.png "Configuration")

**Basic Settings**

* **Widget is active?** — promotional widget activation/deactivation switch on the product page
* **Widget type** — way of presenting the widget [`Textual widget`, `Graphical widget with banner`, `Graphical widget with installments calculator`]
* **Offer type** — type of financing offer promoted [`Zero percent installments`, `Convenient installments`, `Pay later`, `Deferred payments for companies`]

The availability of offer types on the list depends on the individual contract and may differ from that described in the documentation.

**Advanced settings**

* **Widget price element selector** — HTML document element containing the price of the product
* **Widget anchor element selector** — widget container element (widget is embedded in it)
* **Price change detection — container selector** — HTML document element containing a price element directly or indirectly used to track price changes and recalculate offers
* **Price change detection — container hierarchy level** — DOM hierarchy level used to track price changes and recalculate offers
* **Embedding method** — how the widget is oriented relative to the container element [`INSERT_INTO_FIRST`, `INSERT_INTO_LAST`, `INSERT_BEFORE`, `INSERT_AFTER`]
* **Widget initialization code** — JavaScript script to initialize the widget on the product page (making changes not recommended for non-advanced users without JavaScript knowledge)

Detailed information on the operation of the widget and its configuration options can be found in a separate [ComfinoPay widget documentation](https://comfino.pl/widgets/comfino-magento/en).

![Configuration](images/en/mod_config3_bas.png "Configuration")
![Configuration](images/en/mod_config3_adv.png "Configuration")

**For developers**

* **Use test environment** — developer mode activation/deactivation switch
* **Test environment API key** — a unique access key enabling the module to communicate with the ComfinoPay test API

![Configuration](images/en/mod_config4.png "Configuration")

**Note**

Before launching payments on the production store, disable developer mode to block the use of the ComfinoPay payment test environment.

**Plugin diagnostics**

The section contains technical information about the plugin and the environment (plugin version, store version, PHP and web server version, etc.).\
It also contains a list of recent errors with a preview of the local error log.

![Configuration](images/en/mod_config5.png "Configuration")

Information about developer mode activity is displayed in the `"Plugin diagnostics"` section.
In this mode, the plugin uses the key from the `"For developers"` section to communicate with the ComfinoPay test API. You will also receive a test environment key from a ComfinoPay representative.

## DEACTIVATION

In order to deactivate the module, run the following command in the console from the Magento root directory:

`./bin/magento module:disable Comfino_ComfinoGateway`

## REMARKS

* The ComfinoPay payment module requires you to set the customer's phone number as required in the process of entering the customer's contact details. The phone number is used to send codes and inform the customer about the status of the transaction, at a later stage of ComfinoPay payment processing. In case of absence of a telephone number, the ComfinoPay payment transaction will end with an error displayed on the store's website.
* The module notification system requires the availability of the HTTP "PUT" method. The module receives information about changes in the status of ComfinoPay transactions and changes the internal order status in Magento accordingly. In case of problems with notifications, check the web server settings in your hosting panel.
* Magento cron must be running (`bin/magento cron:install`). Order cancellations and error reports that could not be sent to ComfinoPay at once are queued and sent by cron every few minutes. When queued requests wait for more than 30 minutes, the admin panel shows a message and the `"Plugin diagnostics"` section shows how many are waiting.
* The ComfinoPay debug log and error log are written to daily files in `var/log` (`comfino_debug-YYYY-MM-DD.log`, `comfino_errors-YYYY-MM-DD.log`); the last 30 files are kept. Customer data fields (name, e-mail, phone, address, tax ID), keys and signatures are masked in them.
* Custom stylesheets (paywall, banner and calculator) must be `https://` addresses on your shop's own domain.
* **Upgrading from 4.0.x with debug mode used:** if debug mode was ever switched on in version 4.0.x, clear both logs in the `"Plugin diagnostics"` section after upgrading and replace your ComfinoPay API key with a new one.
* Customer and order data required by the ComfinoPay module:
    * Customer:
        * first name - 2-63 characters
        * last name - 2-63 characters
        * email address - valid e-mail address
        * valid Polish mobile phone number (expected format: xxxxxxxxx, xxx xxx xxx, +48 xxxxxxxxx).
    * Cart:
        * total amount
        * list of products
            * name
            * quantity
            * price
    * Address:
        * street
        * building number
        * apartment number
        * postal code
        * city
        * country code

## PRIVACY

Information for your privacy notice and records of processing.

* **What is sent to ComfinoPay when an order is placed:** the customer's first and last name, e-mail address, phone number, delivery address, IP address and tax ID (when given), and the cart (products, prices, delivery cost). ComfinoPay needs them to open the financing application.
* **Where it goes:** the ComfinoPay API (`api-ecommerce.comfino.pl`; `api-ecommerce.craty.pl` in the test environment). The payment widget and the paywall are loaded by the shopper's browser from the ComfinoPay CDN (`sdk.comfino.pl`; `sdk.craty.pl` in the test environment), so these servers receive the shopper's IP address like any other web server.
* **View statistics:** the `"Allow collecting ComfinoPay plugin view statistics"` setting is off by default. When on, the widget and the paywall report their views and the shopper's interactions with them (e.g. offer and instalment changes) to ComfinoPay. Linking product page visits with the checkout additionally requires the shopper's consent to analytics cookies in your shop.
* **Error reports:** technical errors of the module are reported to ComfinoPay with the shop's platform, PHP and server software versions. Customer data fields, keys and signatures are masked before a report is sent or written to a log.
* **What the module keeps in your shop:** the chosen financing offer, its term, the promotional code and the tracking ID are stored with the order's payment and are deleted with the order. The ComfinoPay logs keep the last 30 daily files, with customer data fields masked. Queued requests (order cancellations, error reports) hold no unmasked customer data and are removed after delivery, or after 7 days at the latest. Deleting a customer account therefore needs no extra step in the module.

## COMFINO PROCESS FLOW

![Flow](images/comfino-flow.png "Flow")
