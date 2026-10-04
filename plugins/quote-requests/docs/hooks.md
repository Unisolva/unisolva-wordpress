# Hooks, template tags and styling

This page lists what you can use from your theme or from a small plugin of your own. Anything that is not listed here is internal and can change between versions.

- [`quote_requests_fields`](#quote_requests_fields) (filter): change the fields of the quote form.
- [`quote_requests_validate`](#quote_requests_validate) (filter): add your own checks to a request.
- [`quote_requests_created`](#quote_requests_created) (action): run code after a request is stored.
- [`quote_requests_legacy_order_statuses`](#quote_requests_legacy_order_statuses) (filter): keep or drop three old quote order statuses.
- [`quote_requests_show_crm_state`](#quote_requests_show_crm_state) (filter): show the CRM state of requests in the admin.
- [`QUOTE_REQUESTS_HOLD_UPGRADE`](#quote_requests_hold_upgrade) (constant): postpone the data upgrade after an update.
- [Template tags and shortcodes](#template-tags-and-shortcodes)
- [CSS custom properties](#css-custom-properties)

Put code for the hooks in a small plugin of your own or in your child theme's `functions.php`, not in the Quote Requests folder, which an update replaces.

## `quote_requests_fields`

```php
apply_filters( 'quote_requests_fields', array $fields ): array
```

Changes the list of form fields. The list is what you see on the settings screen under "Form fields", with the changes the filter makes on top. It decides which fields the quote form shows, the order, the checks on each field, and what the emails and the admin view list.

Each entry of the list is an array with these keys:

| Key | Meaning |
|---|---|
| `key` | The name of the field: lowercase letters, digits and underscores, up to 40 characters, unique. Anything else in the key is removed. |
| `type` | `text`, `textarea`, `email`, `tel`, `number`, `select` or `checkbox`. Any other value becomes `text`. The built-in fields keep their own type. |
| `label` | The text shown to the visitor, in emails and in the admin view. Up to 100 characters. An empty label becomes the built-in label, or the key for a field of your own. |
| `help` | Optional hint shown under the field. Up to 200 characters. |
| `required` | `true` when the visitor must fill the field in. Defaults to `false` for a field of your own; a built-in field keeps its own default. |
| `show` | `false` hides the field: it is not drawn and not read from the request. Default `true`. |
| `options` | For `select` only: a list of arrays with `value` and `label`. A value is up to 100 characters. A `select` without options is left out. |
| `system` | `true` for the six built-in fields. Set by the plugin; what you set is ignored. |

What the plugin does with the list you return:

- The six built-in fields (`name`, `phone`, `email`, `company`, `region`, `message`) cannot be removed. One that you leave out is put back with its defaults, after the others.
- `name` and `message` are always shown. `name` is always required. `message` is required only when the visitor's quote list is empty.
- Whether `phone` and `email` are required follows the contact rule on the settings screen, not the `required` key. The contact rule is enforced after the filter: a contact field the rule makes mandatory is shown whatever your callback returns, and with the rule "at least one of phone and email", phone is shown if your callback hides both.
- A form can have 20 fields of your own. Further ones are left out. A field with a key that is already used is left out.
- These keys are reserved and cannot be used for a field of your own: `token`, `website`, `items`, `consent`, `action`, `region_country`, `landing`, `referrer`, `page`, `tz`, `qr_refresh`. An entry with one of them is dropped.
- The order of the list is the order on the form and in the emails.
- The result is cleaned again. A return value that is not an array is ignored: the form stays as it is saved in the settings.

Things to know:

- The filter runs once per request. The plugin builds the list the first time it needs it and keeps it for the rest of the request. Add your callback when your plugin or theme loads, and keep it free of side effects. If you add a callback later, or its answer must change later in the same request, call `Quote_Requests\Fields::flush()` and the list is built again the next time it is needed.
- A field added by the filter does not appear in the settings table and is not saved with the settings. Only the code that adds it controls it.
- A value that was stored for a field stays in the old record, with the label it had at the time, even if the field is later removed or renamed.

Example: hide the company field and add two fields in front of the message.

```php
add_filter(
    'quote_requests_fields',
    static function ( array $fields ): array {
        // Hide a built-in field.
        foreach ( $fields as $i => $field ) {
            if ( 'company' === $field['key'] ) {
                $fields[ $i ]['show'] = false;
            }
        }

        // Add two fields of your own, in front of the message.
        $new_fields = array(
            array(
                'key'      => 'delivery_date',
                'type'     => 'text',
                'label'    => 'Delivery date',
                'help'     => 'When do you need the products?',
                'required' => false,
            ),
            array(
                'key'      => 'project_type',
                'type'     => 'select',
                'label'    => 'Project type',
                'required' => true,
                'options'  => array(
                    array(
                        'value' => 'new',
                        'label' => 'New build',
                    ),
                    array(
                        'value' => 'renovation',
                        'label' => 'Renovation',
                    ),
                ),
            ),
        );
        $position   = array_search( 'message', array_column( $fields, 'key' ), true );
        array_splice( $fields, (int) $position, 0, $new_fields );

        return $fields;
    }
);
```

## `quote_requests_validate`

```php
apply_filters( 'quote_requests_validate', array $errors, array $data, array $cfg ): array
```

Runs once for each request, after the built-in checks of the visible fields, the contact rule and the consent box. It runs for requests sent with and without JavaScript. It does not run when the form token, the honeypot or the rate limit has already rejected the request.

- `$errors`: the errors found so far, as `field key => message`. Any error left in the list rejects the request: nothing is stored and the visitor sees the messages.
- `$data`: the cleaned values: `name`, `phone`, `email`, `company`, `message`, `region_country`, `region`, `region_name`, `consent` and `custom`. `custom` is a list of `key`, `label`, `type` and `value` for each field of your own that has a value. Empty fields are left out of it. `consent` is `false` whenever the consent box is turned off, whatever the request carried. The value of a `number` field is written with the digits 0 to 9, also when the visitor typed Arabic-Indic digits.
- `$cfg`: the configuration the checks ran with: `fields` (the visible field definitions), `contact_rule`, `require_consent` and `has_items` (whether the quote list has products). It also has a `region` key, a callable the plugin uses to look up regions. It is internal: do not call it or rely on it.

Return the errors array, with your errors added. The list you return replaces the list passed in. Errors you leave out are removed, and the request is accepted if the returned list is empty. Use the key of a field to show the message beside that field. A key that is not a field is shown in the summary only. Write messages as plain text; they are escaped when shown. If the callback returns anything that is not an array, the filter is ignored. Entries whose key is not a string or number, or whose message is not a non-empty string, are dropped.

Because the filter also sees the errors found so far, check `isset( $errors['message'] )` before adding one for the same field, so you do not replace a message that is already there. If you need `$data` or `$cfg`, pass the number of arguments as the fourth argument of `add_filter()`.

Example: ask visitors to describe the request without links.

```php
add_filter(
    'quote_requests_validate',
    static function ( array $errors, array $data ): array {
        if ( ! isset( $errors['message'] ) && preg_match( '#https?://#i', $data['message'] ) ) {
            $errors['message'] = 'Please describe your request without links.';
        }
        return $errors;
    },
    10,
    2
);
```

## `quote_requests_created`

```php
do_action( 'quote_requests_created', int $quote_id )
```

Fires once for each request, after the record is stored and after the plugin has handed the emails to WordPress. A failed email does not stop it, and the record exists whatever your code does. The ID is the post ID of the record.

The action runs while the visitor waits for the answer, so keep it short. Do not let your callback throw: the record already exists, and the visitor would see a failure for a request that was in fact stored. For a call to another service, use a short timeout, or `'blocking' => false` as below.

To read the record, call `Quote_Requests\Store::get()`. It returns `null` when the ID is not a quote record, or an array with more keys than listed here (for example `crm_state`, which is internal: do not rely on it). The table shows the keys meant for use:

| Key | Meaning |
|---|---|
| `id`, `ref`, `date` | Post ID, the reference such as `Q-2026-0001`, and the time as an ISO 8601 string (UTC). |
| `name`, `phone`, `email`, `company`, `message` | What the visitor entered. Empty strings when the form does not ask for them. |
| `region_country`, `region_code`, `region_name` | Country code, region code (empty when the visitor typed a region) and region name. |
| `items` | The products, each with `product_id`, `sku`, `name`, `family` and `qty`. |
| `fields` | Your own fields: a list of `key`, `label`, `type` and `value`, with the label the field had when the request was sent. A checkbox value is `1`. In 0.1 the extra details field had its own `details` key in the record. In 0.2 it is an entry of `fields` with the key `details`. |
| `consent` | `given`, `time`, `text` and `privacy_url`. |
| `client` | Visitor details such as `ip`, `browser`, `os`, `device`, `language`, `page`, `landing` and `referrer`. Empty when "Store visitor details" is off. |
| `mail` | The result of each email: `admin` and `customer`, each with a `state` and a `time`. |

Example: send the request to another service, without waiting for the answer.

```php
add_action(
    'quote_requests_created',
    static function ( int $quote_id ): void {
        $quote = Quote_Requests\Store::get( $quote_id );
        if ( null === $quote ) {
            return;
        }

        $products = array();
        foreach ( $quote['items'] as $item ) {
            $products[] = $item['name'] . ' x ' . $item['qty'];
        }

        $extra = array();
        foreach ( $quote['fields'] as $field ) {
            $extra[ $field['key'] ] = $field['value'];
        }

        wp_remote_post(
            'https://crm.example.com/api/leads',
            array(
                'timeout'  => 5,
                'blocking' => false,
                'headers'  => array( 'Content-Type' => 'application/json' ),
                'body'     => wp_json_encode(
                    array(
                        'reference' => $quote['ref'],
                        'name'      => $quote['name'],
                        'email'     => $quote['email'],
                        'phone'     => $quote['phone'],
                        'products'  => $products,
                        'fields'    => $extra,
                    )
                ),
            )
        );
    }
);
```

## `quote_requests_legacy_order_statuses`

```php
apply_filters( 'quote_requests_legacy_order_statuses', bool $register ): bool
```

Some shops kept their quotes as WooCommerce orders before they used this plugin, with the order statuses `wc-quote-requested`, `wc-quote-approved` and `wc-quote-rejected`. Once nothing registers those statuses, such orders drop out of the order list. So the plugin registers the three statuses on a site that has orders with one of them, and only there. It does nothing else with them: Quote Requests does not create orders.

The plugin looks for such orders once, when the data upgrade runs and each time the plugin is activated, and stores the answer in the option `quote_requests_legacy_statuses` (`yes` or `no`). `$register` is that answer. It is also `true` while there is no answer yet, for example while the data upgrade is held, so the orders of an existing site never disappear.

Return `true` to register the statuses, `false` to leave them out. To have the plugin look again, deactivate and activate it.

```php
// This shop never had such orders, or has dealt with them: never register the statuses.
add_filter( 'quote_requests_legacy_order_statuses', '__return_false' );
```

## `quote_requests_show_crm_state`

```php
apply_filters( 'quote_requests_show_crm_state', bool $show ): bool
```

Every stored request has a CRM state, kept for code that passes requests on to another system. The plugin has no such connection of its own, so the state is not shown. Return `true` to show it under WooCommerce > Quote requests: a CRM column and a filter in the list, and a row on the request. The default is `false`. The state itself is internal and its values can change.

```php
add_filter( 'quote_requests_show_crm_state', '__return_true' );
```

## `QUOTE_REQUESTS_HOLD_UPGRADE`

Version 0.2 stores form fields in a new way. After the plugin is updated from 0.1, a one-time data upgrade converts the saved settings and moves the old "details" value of each stored request into the new storage. By default it runs on the first page load after the update.

To postpone it, define the constant in `wp-config.php`, above the line that says "That's all, stop editing":

```php
define( 'QUOTE_REQUESTS_HOLD_UPGRADE', true );
```

Any truthy value holds the upgrade, including `1` and `'yes'`. The string `'false'` is truthy too, so it also holds it. Only `false`, `0`, an empty string or no constant lets the upgrade run.

While the upgrade is held:

- Nothing is converted in the database. The plugin reads 0.1 settings as 0.2 settings on every request, and shows the old details value of a stored request as one more field.
- The quote form, the emails, the admin view and the privacy tools work as they do after the upgrade.
- New requests are stored in the new format.
- Saving the settings screen writes the settings in the 0.2 form.

To release the upgrade, remove the line, or set the value to `false`. The next page load that runs WordPress starts it. It converts the settings, then moves the old values of up to 500 stored requests per page load, so a site with many requests needs several page loads. When none are left, the plugin records the data version in the option `quote_requests_db_version` and does nothing more. Running it again is safe. The upgrade also looks once for orders with the old quote order statuses, see [`quote_requests_legacy_order_statuses`](#quote_requests_legacy_order_statuses).

## Template tags and shortcodes

Both template tags return HTML as a string. They do not print it, and the output is already escaped. Both return an empty string while no quote page is chosen in the settings.

### `quote_requests_button( int $product_id = 0 ): string`

The "Add to quote" control for one product. `0`, the default, means the current post. It returns an empty string when the product does not exist or is not published. The label comes from the "Button label" setting. By default the plugin adds this button to product pages and product lists by itself; the two "Add the button" settings turn that off.

```php
if ( function_exists( 'quote_requests_button' ) ) {
    echo quote_requests_button( 123 );
}
```

### `quote_requests_link(): string`

A link to the quote page with an icon and a live count of the products in the list. The count is filled in by the plugin's script.

```php
if ( function_exists( 'quote_requests_link' ) ) {
    echo quote_requests_link();
}
```

### Shortcodes

| Shortcode | Output |
|---|---|
| `[quote_requests]` | The quote page: the list of products and the form. Put it on the page you choose as the quote page in the settings. |
| `[quote_requests_button]` or `[quote_requests_button id="123"]` | The same control as `quote_requests_button()`. Without `id` it uses the current post. |
| `[quote_requests_link]` | The same link as `quote_requests_link()`. |

## CSS custom properties

The quote page, the button and the link read these custom properties. Set them on `:root`, on `body` or on a wrapper element in your theme's CSS to match your colours. Each one has a default.

| Property | Used for | Default |
|---|---|---|
| `--qr-accent` | Buttons, focus outlines and checkboxes | `#2271b1` |
| `--qr-accent-hover` | Buttons when hovered | `#135e96` |
| `--qr-on-accent` | Text on a button | `#fff` |
| `--qr-text` | Text | `#1e1e1e` |
| `--qr-muted` | Secondary text and hints | `#50575e` |
| `--qr-border` | Borders of fields and cards | `#c3c4c7` |
| `--qr-error` | Error text and error borders | `#b32d2e` |
| `--qr-radius` | Corner radius | `6px` |
| `--qr-surface` | Background of the form and the product cards | `#fff` |

```css
:root {
    --qr-accent: #0b5cad;
    --qr-accent-hover: #084a8c;
    --qr-radius: 2px;
}
```
