=== Pay Invoices With Amazon ===

Plugin Name: Pay Invoices With Amazon
Author: Amazon Pay
Author URI: https://pay.amazon.com/business/pay-invoices-with-amazon
Contributors: zengy, aaronholbrook, ivande, chetmac, pdclark
Tags: amazon pay, payments, checkout, online payments, ecommerce
Stable tag: 1.3.1
Requires at least: 5.6
Tested up to: 6.4.1
Requires PHP: 5.6.20
Text Domain: piwa
Domain Path: /languages
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Enables Amazon Pay integration using the WordPress block editor. Accept payments using Amazon Pay, providing a seamless experience for your customers.

== Description ==

The PIWA plugin by [Zeek](https://zeek.com/) is a simple and effective way to incorporate Amazon Pay into your website.

The plugin includes:
- **Seamless Integration** with the WordPress block editor.
- **Enhanced Security** by leveraging Amazon’s proven payment processing infrastructure.
- **One-Click Checkout** for customers who want a fast and simple payment experience.

Please ensure that your WordPress installation meets the required PHP version (5.6 or higher) and WordPress version (5.6 or higher). Visit our [GitHub repository](https://github.com/ZeekInteractive/pay-invoices-with-amazon/) for further details, documentation, and support.

**Creating Invoices**

To create invoices, add either the `Adjustable Price` or `Fixed Price` block to a page or post:

1. Navigate to the page or post where you want to add the payment block.
2. Click on the `+` button or type `/` to add a new block.
3. Search for `Adjustable Price` or `Fixed Price` in the block library.
4. Click on the block to add it to your page or post.

Once the block is added, you can either email a link to that page to a customer or include a link in an invoice sent from your accounting software of choice, such as QuickBooks, Xero, Zoho, FreshBooks, or Harvest.

The customer will need to have or create an Amazon account to make the payment. Once the payment is made, it will be processed by Amazon and the funds will be transferred to your linked bank account.

For more information on how to set up and manage your Amazon Pay account, please refer to the [Amazon Pay Help Center](https://pay.amazon.com/help/JXYC9GRJATFBSXN).

**Receiving Payments**

To receive payments, connect the plugin to your Amazon Pay account using one of the methods the under `WP Admin > Pay Invoices With Amazon > Settings`:

1. **Connect Automatically**: This is the easiest and most secure. Click the `Connect Amazon Pay Account` button to log in with your Amazon account and have credentials configured automatically.
2. **Send Public Key**: Copy-and-paste a plugin-generated Public Key into Amazon Integration Central, then copy-and-paste the returned Public Key ID.
3. **Receive Private Key**: Generate a Private Key in Amazon Integration Central, then drag-and-drop the downloaded file onto the Settings field and copy-and-paste the Public Key ID.

Once payments are processed, individual payments will be authorized by Amazon within 24 hours. After that period, click the linked "Reference ID" for the payment to go to Seller Central. Clicking "Collect Payment" in Seller Central will transfer the authorized funds.

Further information can be found at [Finding your Amazon Pay keys and IDs](https://pay.amazon.com/help/202022560) and in the [GitHub repository](https://github.com/ZeekInteractive/pay-invoices-with-amazon/).

**Using as a Shortcode**

While the blocks provide a visual preview if using the WordPress block editor, a shortcode is also available for use in the block editor, classic editor, or various layout plugins. The below examples can be copy-and-pasted for testing or custom configuration:

Payment form where customer sets the amount:

[piwa]

Payment button where the amount is $100.50:

[piwa 100.50]

Payment button where the amount is $100.50 and the title is Business Consulting:

[piwa 100.50 "Business Consulting"]

Payment button where the customer sets the amount and inputting an invoice reference number is required:

[piwa input-invoice]

Long-form to display a payment button set to $100.50 for Business Consulting:

[piwa amount="100.50" title="Business Consulting"]


**Third-Party Services**

**Pay Invoices with Amazon** integrates with Amazon Payments to process invoice payments made through the plugin.
**Third-Party Service Links and Policies**

- Amazon Payments Terms of Service: https://pay.amazon.com/help/201212430
- Amazon Payments Privacy Policy: https://pay.amazon.com/help/201212490

By using the plugin, you acknowledge and consent to the use of Amazon Payments for payment processing. We ensure that all data transmissions are secure and in compliance with legal standards.

== Installation ==

1. Upload the PIWA plugin to your WordPress plugins directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Follow the instructions on [Finding your Amazon Pay keys and IDs](https://pay.amazon.com/help/202022560) or use the `Connect Amazon Pay Account` button to connect to your Amazon Pay account under `WP Admin > Pay Invoices with Amazon > Settings`.

== Frequently Asked Questions ==

= What is Amazon Pay? =

[Amazon Pay](https://pay.amazon.com/business) allows Amazon customers to pay for services on third-party websites using the payment methods stored in their Amazon accounts.

= Is this plugin free? =

Yes, this plugin is free to use, but transactions processed with Amazon Pay will be subject to [Amazon's usual charges](https://pay.amazon.com/help/201212280).

= Where can I get support? =

For support, please post an issue to the [GitHub repository](https://github.com/ZeekInteractive/pay-invoices-with-amazon/issues/).

= What are the prerequisites for using this plugin? =

`WordPress version 5.6` or later, `PHP version 5.6` or later, and an [Amazon Pay account](https://pay.amazon.com/business).

== Screenshots ==

1. Fixed Price block with optional title.
2. Adjustable Price block, allowing customers to enter payment amount.
3. Adjustable Price block with optional field for Invoice Number.
4. Message on successful payment.
5. Payment history with links to source page and customer account.
6. Plugin Settings with links to Amazon Pay Seller Central and technical documentation.

== Changelog ==

= 1.0 =
* Initial release.

== Upgrade Notice ==

= 1.0 =
* Initial version of the plugin.
