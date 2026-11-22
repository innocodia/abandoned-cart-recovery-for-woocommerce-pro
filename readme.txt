=== Better Recovery Pro - Abandoned Cart Recovery for WooCommerce ===
Contributors: innocodia
Tags: abandoned cart, cart recovery, woocommerce, follow-up emails, conversion
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pro add-on for Better Recovery: follow-up stats, smart conditions, blacklist, products report and more.

== Description ==

Better Recovery Pro adds advanced recovery and reporting features to the free
"Better Recovery - Abandoned Cart Recovery for WooCommerce" plugin.

Requires WooCommerce and the free Better Recovery plugin (both active).

= Pro features =

* **Follow-up stats** – sent, open rate, click rate, conversion rate, unsubscribe rate and recovered revenue for every follow-up step and template.
* **Smart conditions** – send a follow-up only to carts that match your rules: cart total and subtotal, items and quantity, weight, products, categories and tags in the cart, checkout type, currency, customer, login status, user role, email, phone, billing and shipping location, first order, and the customer's order history (last order date and amount, number of orders, total spent).
* **Blacklist** – never send follow-ups to listed email addresses or whole domains (e.g. `@competitor.com`).
* **Products report** – see which products are abandoned and recovered most: times abandoned, amount abandoned, times recovered, amount recovered and recovery rate, with search, sorting, date range and CSV export.
* **Blacklisted carts** – a "Blacklisted" filter and count in the Carts report.
* **WhatsApp reminders** – send follow-up steps on WhatsApp through your own Meta WhatsApp Business account (Cloud API): pick an approved template, fill it with the customer's name, cart, coupon and a one-tap recovery link, and see delivered, read, clicked and recovered for each message. Customers who reply STOP are never messaged again. Meta charges for marketing messages.
* **SMS reminders** – send follow-up steps by text message through your own Twilio account, with short recovery links that keep a reminder to one SMS, an automatic "Reply STOP to opt out" line, delivery reports and STOP / START handling.
* **WhatsApp and SMS opt-in** – optional checkboxes under the checkout phone field (classic and block checkout); each channel then goes only to customers who ticked its box.
* **Phone GDPR consent** – a consent notice with your own message and a "No thanks" link under the checkout phone field (classic and block checkout). Customers who opt out never have their phone number saved.
* **License & updates** – activate your license under Better Recovery → License for automatic updates.

== Installation ==

1. Install and activate WooCommerce and the free "Better Recovery - Abandoned Cart Recovery for WooCommerce" plugin.
2. Upload and activate Better Recovery Pro.
3. Go to Better Recovery → License and enter your license key.

== Frequently Asked Questions ==

= Does Pro work on its own? =

No. Pro is an add-on: it needs WooCommerce and the free Better Recovery plugin to be active.

= What happens to my settings if Pro is deactivated? =

Your settings and data are kept. Pro-only features stop until Pro is active again: steps with conditions are paused (never sent to everyone), WhatsApp and SMS steps are skipped, and the blacklist, phone consent notice and opt-in checkboxes are no longer applied.

= What do I need for WhatsApp reminders? =

A Meta WhatsApp Business account with the Cloud API, a phone number added to it, a permanent access token, and at least one approved message template. Enter them under Better Recovery → Integrations → WhatsApp, then paste the webhook URL shown there into your Meta app so delivery reports and STOP replies reach your store.

= What do I need for SMS reminders? =

A Twilio account with an SMS-capable phone number (or an approved sender name or Messaging Service). Enter the Account SID, Auth Token and sender under Better Recovery → Integrations → SMS, then paste the inbound URL shown there into your Twilio number's messaging settings so STOP replies reach your store. Sending to US numbers requires A2P 10DLC registration with Twilio. Twilio charges per SMS part.

== Licence and credits ==

Better Recovery Pro is licensed GPLv2 or later. It includes the Freemius WordPress SDK (in `license/`) for licence activation and updates, which is licensed GPLv3; the plugin as a whole is distributed under the GPL terms both allow.

== Changelog ==

= 1.0.1 – 2026-11-22 =
* New: WhatsApp reminders through Meta's WhatsApp Cloud API: connection settings with verification, approved-template picker, merge tags in template slots and the link button, delivered / read / failed status, and a STOP list.
* New: SMS reminders through Twilio: connection settings with verification, short recovery links, an automatic opt-out line, delivery reports, and STOP / START handling.
* New: Optional WhatsApp and SMS opt-in checkboxes at checkout (classic and block checkout).
* Improvement: Plugin data is removed through the licensing system's uninstall step instead of a separate uninstall file, including the WhatsApp / SMS settings and STOP lists (only when "Delete Plugin Data on Uninstall" is on).
* Tweak: License changed to GPLv2 or later, the same as WordPress.
* Tweak: Requires Better Recovery (free) 1.0.2 or newer.

= 1.0.0 – 2026-11-13 =
* New: Initial release.
* New: Follow-up stats per step and template – sent, opened, clicked, converted and unsubscribed, with open, click, conversion and unsubscribe rates and recovered revenue.
* New: Smart conditions for follow-up steps (cart, product, customer, location and order-history rules), checked before every send. Steps with conditions are paused, never sent to everyone, while Pro is inactive.
* New: Blacklist of email addresses and `@domains` that never receive follow-ups (exact domain match, not case sensitive).
* New: Products report – times and amount abandoned, times and amount recovered, and recovery rate per product, with search, sorting, date range and CSV export.
* New: "Blacklisted" filter and count in the Carts report.
* New: Phone GDPR consent notice with an editable message and a "No thanks" opt-out, on classic and block checkout.
* New: License activation and automatic updates (Better Recovery → License).
