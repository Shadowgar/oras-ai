# WooCommerce Integration

## Role

WooCommerce is authoritative for ORAS purchasable products such as Observer Passes and event tickets when represented as WooCommerce products.

## Live fields

- product status;
- price/sale price;
- stock/availability;
- purchasability;
- canonical product URL;
- variation state;
- normal cart/checkout behavior.

## Assistant behavior

The assistant may report currently retrieved product options/price/availability and provide canonical purchase links.

## Prohibited initial behavior

- card/payment-data handling;
- autonomous order finalization;
- bypassing WooCommerce checkout validation;
- claiming availability without live retrieval.

## Observer Pass recommendations

An observing recommendation may explain why a night is favorable, report live pass availability, and offer a purchase link. Checkout remains a member-driven WooCommerce workflow.
