# WooCommerce MCF Fulfillment

WooCommerce plugin for Amazon Multi-Channel Fulfillment (MCF) integration via SP-API.

## Status
- [x] Phase A: SP-API credential testing
- [x] Phase B: MCF order lifecycle testing
- [ ] Phase C: SQS notifications setup
- [x] Phase D: WooCommerce installation
- [x] Phase E: Plugin development (core features complete)

## Structure
- `/tests/` - Standalone PHP scripts for SP-API testing
- `/plugin/amazon-mcf/` - WooCommerce plugin (Phase E)

## Requirements
- PHP 8.2+
- Composer
- Amazon SP-API credentials (private app with self-authorization)
