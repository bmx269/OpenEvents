# Setting up payments

OpenEvents Tickets can take payment three ways. Buyers pay on Stripe's or PayPal's own pages, so card details never reach your site, and OpenEvents adds no fees on top of what the provider charges.

| Method | Good for | Needs |
| --- | --- | --- |
| **Pay at the door** (manual) | Cash, invoices, e-transfer | Nothing. Tickets are reserved immediately and marked unpaid until you mark them paid in **Events → Orders**. |
| **Stripe** | Cards, Apple Pay, Google Pay | A Stripe account, its secret key, and a webhook |
| **PayPal** | PayPal balance, Venmo, cards via PayPal | A PayPal Business account, REST app credentials, and a webhook |

Free tickets (price 0) never need a payment method.

Turn the Tickets module on in **Events → Settings → Modules**. Payment methods and the currency live in **Events → Settings → Tickets**.

## Stripe

1. In the Stripe dashboard, open **Developers → API keys**. Copy the **secret key**: use `sk_test_…` while testing, `sk_live_…` for real sales. A restricted key works if it can write Checkout Sessions and Refunds.
2. In **Developers → Webhooks**, add an endpoint:
   - URL: `https://your-site.example/wp-json/openevents/v1/gateways/stripe/webhook` (shown in the settings screen)
   - Events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `charge.refunded`
3. Copy the endpoint's **signing secret** (`whsec_…`).
4. In **Events → Settings → Tickets → Card (Stripe)**, turn it on, paste both values, and save.

To test, buy a ticket with card `4242 4242 4242 4242`, any future expiry, and any CVC. The order should show as **Paid** in **Events → Orders**. Refund it there and the refund appears in Stripe.

## PayPal

1. At [developer.paypal.com](https://developer.paypal.com) → **Apps & Credentials**, choose **Sandbox** (or **Live**) and create an app. Copy its **Client ID** and **Secret**.
2. In the app's **Webhooks** section, add:
   - URL: `https://your-site.example/wp-json/openevents/v1/gateways/paypal/webhook`
   - Events: `CHECKOUT.ORDER.APPROVED`, `PAYMENT.CAPTURE.COMPLETED`, `PAYMENT.CAPTURE.DENIED`, `PAYMENT.CAPTURE.REFUNDED`
3. Copy the **Webhook ID**.
4. In **Events → Settings → Tickets → PayPal**, choose the same mode (Sandbox or Live), paste the client ID, secret, and webhook ID, turn it on, and save.

To test, pay with one of the sandbox personal accounts listed under **Testing tools → Sandbox accounts**.

## Keeping keys out of the database

Any setting can be defined in `wp-config.php` instead. Constants override the settings screen, which then shows the field as "Set in wp-config.php":

```php
define( 'OPENEVENTS_STRIPE_SECRET_KEY', 'sk_live_…' );
define( 'OPENEVENTS_STRIPE_WEBHOOK_SECRET', 'whsec_…' );
define( 'OPENEVENTS_PAYPAL_CLIENT_ID', '…' );
define( 'OPENEVENTS_PAYPAL_CLIENT_SECRET', '…' );
define( 'OPENEVENTS_PAYPAL_WEBHOOK_ID', '…' );
define( 'OPENEVENTS_PAYPAL_MODE', 'live' );
```

The pattern is `OPENEVENTS_{GATEWAY}_{SETTING}`. Secrets saved in the settings screen are stored in a non-autoloaded option and are never sent back to the browser; the screen shows only the last four characters.

## How an order flows

1. The buyer picks tickets. Their seats are **held for 20 minutes**, so nobody else can take them while they pay.
2. They're sent to Stripe or PayPal and pay there.
3. The order completes when the buyer returns (the site confirms with the provider and checks the amount) or when the signed webhook arrives, whichever comes first. Each order completes exactly once.
4. Abandoned checkouts release their seats when the hold expires.
5. If a payment arrives after its hold expired and the seats have since been sold, the order is still completed (you never keep money without issuing tickets). It's flagged as **over capacity**, and an admin notice asks you to review it.

Refunds started in **Events → Orders** go through the provider. Refunds made in the Stripe or PayPal dashboard are picked up by the webhook. Either way the tickets are cancelled and the seats freed.

## Adding another payment method

Implement `OpenEvents\Modules\Tickets\Gateway` (extending `AbstractGateway` gives you settings storage and constants support) and register it:

```php
add_filter( 'openevents/tickets/gateways', function ( array $gateways ) {
	$gateways[] = new My_Square_Gateway();
	return $gateways;
} );
```

Your gateway's `settings_fields()` appear automatically in the Tickets settings tab, and its webhook endpoint is `/wp-json/openevents/v1/gateways/{id}/webhook`.
