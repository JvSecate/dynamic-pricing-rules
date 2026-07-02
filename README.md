# Store Dynamic Pricing

Custom WooCommerce dynamic pricing plugin for quantity discounts, product/category bulk rules, and buy X get Y offers.

## Features

- Quantity tiers like 2-3 items = 5% off, 4-5 items = 10% off, 6+ items = 15% off
- Product or category scoped rules with searchable product selection
- Percentage discounts, fixed amount discounts, and fixed unit prices
- Buy X get Y offers, such as buy 2 and get 1 free
- Admin settings page under WooCommerce -> Dynamic Pricing, no JSON editing required
- Developer filter: `store_dynamic_pricing_rules`

## Default rules

The plugin starts with one enabled rule:

| Quantity | Discount |
|---|---|
| 2-3 items | 5% off |
| 4-5 items | 10% off |
| 6+ items | 15% off |

It also includes a saved but disabled `Buy 2, get 1 free` rule. Enable it from WooCommerce -> Dynamic Pricing when that promotion should run.

## Admin setup

1. Go to WooCommerce -> Dynamic Pricing.
2. Use Add rule for each separate discount or offer.
3. Choose the rule type:
   - Quantity discount
   - Buy X, get Y free
4. Set Apply to:
   - All products
   - Specific products
   - Specific categories
5. For a product-specific discount, choose Specific products and search for the product.
6. Edit Discount label to change the text after the colon at cart/checkout.
7. Edit Checkout label to change the text before the colon at cart/checkout.
8. Fill the discount tiers or buy/get offer fields.
9. Save pricing rules.

You can remove a rule with Remove rule. Removed rules are not saved on the next submit.

To discount one specific product by 10% when customers buy 2 or more:

| Field | Value |
|---|---|
| Apply to | Specific products |
| Specific products | Select the product |
| Checkout label | Discount |
| Discount label | Quantity discount |
| Count quantity by | Same product together |
| Min qty | 2 |
| Max qty | empty |
| Discount type | Percent off |
| Amount | 10 |

## Saved Rule Fields

- `enabled`: true or false
- `type`: `quantity_tier` or `buy_x_get_y`
- `label`: customer-facing discount label
- `scope`: `all`, `product`, or `category`
- `product_ids`: product or variation IDs when scope is `product`
- `category_ids`: WooCommerce product category IDs when scope is `category`
- `quantity_mode`: `line`, `product`, `category`, or `cart`

## Quantity tier discount types

- `percent`: percentage off the item price
- `fixed`: fixed amount off the item price
- `fixed_price`: set a fixed item price

For open-ended tiers, use `"max": 0`.
