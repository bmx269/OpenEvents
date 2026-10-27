# Event Tickets: data model for the RSVP/Tickets importer

From a shallow clone of [event-tickets](https://github.com/the-events-calendar/event-tickets). Everything is stored as posts and post meta. There are **three generations** of data, and an importer must handle all of them.

## RSVP v1 (legacy)
- Tickets: `tribe_rsvp_tickets`, linked to the event by `_tribe_rsvp_for_event`.
- Attendees: `tribe_rsvp_attendees`. There is no order post; attendees from one submission share the `_tribe_rsvp_order` hash.
- Attendee meta: `_tribe_rsvp_product`, `_tribe_rsvp_event`, `_tribe_rsvp_full_name`, `_tribe_rsvp_email`, `_tribe_rsvp_status` (`yes`/`no`), `_tribe_rsvp_checkedin`, `_tribe_rsvp_security_code`, `_tribe_rsvp_attendee_optout`.

## Tickets Commerce (also RSVP v2)
- Ticket `tec_tc_ticket`:
  - Fields: `_tec_tickets_commerce_event`, `_price`, `_type` (`tc-rsvp` marks an RSVP), `_stock`, `_manage_stock`, `total_sales`.
  - Sale window: `_ticket_start_date`/`_time` and `_ticket_end_date`/`_time`, stored as local time and split.
  - Sale price: `_sale_price*`.
- Order `tec_tc_order`, with `post_status` = `tec-tc-{status}`:
  - Gateway: `_tec_tc_order_gateway`, `_tec_tc_order_gateway_order_id`.
  - Items: serialized `_tec_tc_order_items`.
  - Totals: `_tec_tc_order_total_value`, `_tec_tc_order_currency`.
  - Purchaser: `_tec_tc_order_purchaser_*`.
- Attendee `tec_tc_attendee`, with `post_parent` = the order: `_tec_tickets_commerce_{ticket,event,order,full_name,email,security_code,status,checked_in,price_paid}`.
- Statuses: `created`, `pending`, `action-req`, `approved`, `completed`, `denied`, `not-completed`, `refunded`, `reversed`, `voided`.

## Legacy PayPal (Tribe Commerce) and Plus providers
- `tribe_tpp_tickets` / `_orders` / `_attendees` with `_tribe_tpp_*` meta.
- WooCommerce tickets are `product` posts, linked by `_tribe_wooticket_event`. EDD tickets use `_tribe_eddticket_for_event`.

## Capacity
- `_tribe_ticket_capacity`: `-1` means unlimited. Unlimited can also show up as an empty value or `_manage_stock=no`.
- Shared capacity on the event: `_tribe_ticket_use_global_stock` and `_tribe_ticket_global_stock_level`. Per-ticket `_global_stock_mode` is `global`, `capped`, `own`, or `unlimited`, plus `_global_stock_cap`.
- Decrements are read-modify-write `update_post_meta` calls. They are not atomic, so stored stock can be stale. **Recompute sold counts from attendees.**

## Other surfaces
- Check-in: `GET tribe/tickets/v1/qr` (API key plus `security_code`). Details are in serialized `{checkin_key}_details`.
- Emails: `ticket`, `rsvp`, `rsvp-not-going`, `purchase-receipt`, `completed-order`.
- Ticket-enabled post types: the `ticket-enabled-post-types` key of the TEC options (defaults `tribe_events`, `page`), filterable with `tribe_tickets_post_types`.
- Stripe and Square charge a 2% application fee unless a paid licence is present. Refunds are only initiated from Square; Stripe and PayPal refunds arrive by webhook.

## Importer gotchas
1. Detect the RSVP version: option `tickets_rsvp_version`, plus the `rsvp-to-tc` migration (keeps IDs; synthetic orders are flagged `_tec_rsvp_migration_created`).
2. Migrated attendees may still have their data under `_tribe_rsvp_full_name` / `_tribe_rsvp_checkedin` only. Read both key sets.
3. The `docs/RSVP_V2` docs say `tribe_tc_tickets`; the code uses `tec_tc_ticket`.
4. Group v1 attendees by `_tribe_rsvp_order` to synthesise orders.
5. Prices are decimal strings and order items are serialized PHP.
6. **Keep security codes.** They are printed in existing QR codes.
7. Series-pass check-ins clone attendees per occurrence (`_tec_attendee_clone_of`). Fold them into `oe_attendees.occurrence_id`.
