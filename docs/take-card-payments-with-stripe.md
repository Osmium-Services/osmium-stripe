---
title: Take card payments with Stripe
tags: stripe, card payments, apple pay, google pay, checkout, webhook, payments
---

**Stripe** takes card payments on your own checkout page. Customers are never sent away from your site.

## Set it up

1. In your Stripe dashboard, copy the **Publishable** and **Secret** keys, for both test and live.
2. Open **Stripe Settings** and paste the **Test Publishable Key**, **Test Secret Key**, **Live Publishable Key** and **Live Secret Key**.
3. Add the webhook. The settings page shows the **Webhook endpoint** address to add in the Stripe dashboard, with the events `payment_intent.succeeded`, `payment_intent.payment_failed` and `charge.refunded`. Paste the signing secret it gives you into **Webhook Signing Secret**.
4. Tick **Enable Stripe**.
5. Set **Stripe Mode** to **Test** while you check it works, then **Live**.
6. Press **Save Settings**.
7. In **Settings > Payments**, choose Stripe as the **Active Checkout Provider**. Stripe will not show as ready there until the keys are saved.

## Other options

- **Accepted payment types** are shown as payment badges on the site. Apple Pay and Google Pay also need your domain verified in the Stripe dashboard, so they start off.
- **Statement Descriptor** is shown on the customer's card statement alongside your Stripe account name. Up to 22 characters.

Redirect-based methods such as iDEAL, Klarna and Bancontact are not supported.
