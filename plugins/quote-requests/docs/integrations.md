# Quote Requests: other forms

The plugin has its own form on the quote page. This page is for a site that wants another form to send quote requests: a form made with a form plugin, a form in a popup, or your own code. A request that comes in this way is the same as one of the built-in form: it is checked against the same form fields, stored as the same record, emailed to the same people, and it fires the same action.

There are three parts:

- [`quote_requests_submit()`](#quote_requests_submit): the function that stores a request.
- [The quote list in your form](#the-quote-list-in-your-form): a hidden input the plugin fills, and a browser event that clears the list.
- [Elementor Pro](#elementor-pro): a ready-made action for the Form widget, with nothing to code.

At the end there is [a complete adapter for another form plugin](#an-adapter-for-another-form-plugin) in about 30 lines, and a list of [what is not supported](#not-supported).

For the filters and actions of the plugin, see [hooks.md](hooks.md).

## `quote_requests_submit()`

```php
quote_requests_submit( array $fields, array $items = array(), array $args = array() ): array
```

Stores one quote request. It runs the steps the built-in form runs: the rate limit, the validation against the form fields of the settings, the product checks, the record, the emails and the [`quote_requests_created`](hooks.md#quote_requests_created) action.

It does not run the spam checks of the built-in form, which are a signed token and a honeypot that only that form has. **Your form is responsible for its own spam protection.** Call the function only for a submission your form has accepted.

### `$fields`

The values the visitor entered, keyed by field key, as typed and without slashes (use `wp_unslash()` on values you read from `$_POST` yourself).

| Key | Value |
|---|---|
| `name`, `phone`, `email`, `company`, `message` | The built-in fields. |
| `region` | A region code or a region name of the country, as WooCommerce lists them (for example `CA` or `California`). For a country without a region list, one line of text. |
| `region_country` | A two-letter country code. It counts only when the settings let the visitor choose the country. Without it, the default country is used. |
| a key of your own | The value of a field you added on the settings screen. The key is shown there. |
| `consent` | The consent tick, as the built-in form posts it: `1`, `true`, `"1"`, `"on"`, `"yes"` or `"true"` when ticked. Anything else is not a consent. It is needed when "Require a consent checkbox" is on. |

Other keys are ignored. Which fields are required, and how each value is checked, follows the settings: a field that is hidden there is not read, and the contact rule decides about phone and email. So the form you build should ask for the same fields.

### `$items`

The quote list: a list of `array( 'id' => 12, 'qty' => 3 )`, where `id` is a product ID. A product that does not exist or is not published is dropped. With an empty list the message is required, so a request always says something.

`quote_requests_parse_items()` makes this list from the hidden input described below.

### `$args`

| Key | Value |
|---|---|
| `source` | A short label stored on the record, so you can tell where a request came from: for example `elementor` or `popup`. Lowercase letters, digits, dash and underscore, at most 40 characters; other characters are removed. Default `other`. The built-in form stores `form`. |
| `ip` | The visitor address the rate limit counts and the record stores. Default: the address of the current request, read the way the built-in form reads it (with the "Trust proxy header" setting). Give it only when your code runs outside the visitor's own request. A call without a request (WP-CLI, cron) and without `ip` uses an empty address, so all such calls share one rate limit counter: pass the visitor's address when you have it. |
| `page` | The address of the page the form was on. It is stored as the page the request was sent from, where the built-in form stores its own page: "Sent from page" in the admin, the `page` key of `client` in `Quote_Requests\Store::get()`. Only an `http` or `https` address on this site's host is taken, and its path and query are what is stored (`/contact/?from=popup`). Anything else is ignored, and so is the argument when "Store visitor details" is off. |
| `consent_text` | The text beside the consent tick of your form, as the visitor read it. Plain text: tags are removed, and it is cut at 1000 characters. |
| `privacy_url` | The address of the privacy page your consent text links to. Only an `http` or `https` address is taken, cleaned with `esc_url_raw()`. Anything else is ignored. |

The label is shown as "Source" with the visitor details of a request in the admin, it is in the personal data export, and code reads it as the `source` key of `Quote_Requests\Store::get()`.

Give `consent_text` and `privacy_url` when your form words the consent itself: the record of a request must show what the visitor agreed to. When the consent is ticked, they are stored in the consent record in place of the consent text and the privacy page of the settings, which only the built-in form shows. Each one that is absent or empty leaves the value of the settings in place, so a form that passes neither should show the consent text of the settings. Without a ticked consent, or with "Require a consent checkbox" off, no text is recorded at all.

### The answer

A stored request:

```php
array( 'ok' => true, 'id' => 1234, 'ref' => 'Q-2026-0042', 'dropped' => 0 )
```

`id` is the post ID of the record and `ref` the reference the visitor and your team see. `dropped` is the number of lines of `$items` that were left out of the request: a product that does not exist or is not published, a line without a usable product ID, or a line beyond the 50 a request holds. It is `0` when every line was taken. The answer of the built-in form carries the same count under the same name.

A refused request:

```php
array(
    'ok'      => false,
    'code'    => 'invalid',
    'message' => 'Please check the highlighted fields.',
    'errors'  => array( 'phone' => 'Please enter a phone number of 7 to 20 digits.' ),
)
```

| Code | Meaning |
|---|---|
| `invalid` | The validation refused it. `errors` holds one message per field key (`consent` for the consent tick). Show each beside its field. |
| `rate_limited` | Too many requests from this address in the last hour. `errors` is empty; show `message`. |
| `store_failed` | The record could not be saved. `errors` is empty; show `message`. |
| `not_ready` | The function was called too early or without WooCommerce. See below. |

Nothing is stored and no email is sent for a refused request. The messages are plain text in the language of the site: escape them where you print them.

### When to call it

The function is ready once the plugin has registered its data, which it does on the `init` action at priority 10, with WooCommerce active. So call it on `wp_loaded` or later, or on `init` with a priority above 10. A call that comes too early, for example from a callback on `init` at the default priority that runs before the plugin's own, stores nothing and answers with the code `not_ready`. So does a call on a site where WooCommerce is not active. The function never stops the page with an error.

WordPress runs the handlers of its AJAX and REST requests after `init`, so the function is ready in those. The case to watch is code that handles a posted form on `init` itself.

If your code can run while the plugin is not active, check `function_exists( 'quote_requests_submit' )` first.

### `quote_requests_parse_items()`

```php
quote_requests_parse_items( $json ): array
```

Reads the value of the hidden input `quote_requests_items` and returns the list for `$items`. It accepts the value with or without the slashes WordPress adds to posted values. Anything that is not a list of products gives an empty list, never an error: text that is not JSON, a row without a positive whole `id`, a wrong type. A quantity that is missing or not a whole number counts as 1, and at most 50 lines are kept.

## The quote list in your form

The visitor's quote list lives in the browser, not on the server. To send it with your form:

1. Put a hidden input named `quote_requests_items` in the form.

   ```html
   <input type="hidden" name="quote_requests_items">
   ```

   The plugin's script fills every such input with the list as JSON, for example `[{"id":12,"qty":3}]`, and `[]` for an empty list. It does so when the page loads, whenever the list changes, when the input is added to the page later (a popup), after the form is reset and just before the form is sent. Many form builders wrap the name of a field, so an input whose name ends in `[quote_requests_items]`, such as `form_fields[quote_requests_items]`, is filled too.

2. On the server, pass the posted value through `quote_requests_parse_items()` and give the result to `quote_requests_submit()`.

3. When the request is stored, fire the browser event `quote_requests:sent` on `document`. The script then empties the list, the count of the header link and the "Add to quote" buttons, as after a request of the built-in form.

   ```js
   document.dispatchEvent( new CustomEvent( 'quote_requests:sent' ) );
   ```

The script and the list are on every front page of the site, so the input works on any page. It needs JavaScript and the browser's local storage. Without them the input stays empty or holds `[]`, and the request is sent without products, which is why the message is then required.

Fire the event only after a request was really stored. A form that fails and fires it anyway empties the visitor's list for nothing.

## Elementor Pro

The plugin adds an action named "Quote request" to the Form widget of Elementor Pro. There is nothing to code. Without Elementor Pro the plugin works as before, and this part of it is not loaded.

### Set-up

1. Edit the form with Elementor. Under Content > Actions After Submit, add the action **Quote request**.
2. Give each form field its ID: open the field, go to its Advanced tab and fill in ID. A field is passed on when its ID is one of these:

   | ID | Field |
   |---|---|
   | `name`, `phone`, `email`, `company`, `message` | The built-in fields. |
   | `region` | A text or select field. Its value is a region code or a region name of the country. |
   | `region_country` | Only when the settings let the visitor choose the country: a field whose value is a two-letter country code. |
   | the key of a field of your own | As shown on the settings screen of Quote Requests. |
   | `consent` | An Acceptance field. Ticked, it is the consent, and its Acceptance Text is stored with the request as the text the visitor agreed to (without its tags). Add it when "Require a consent checkbox" is on. |
   | `quote_requests_items` | A Hidden field, with no default value. It carries the visitor's quote list. |

   A field with any other ID is not part of the quote request. Elementor still handles it in its own actions.
3. Ask for the same fields as the settings of Quote Requests require. The plugin checks the submission against those settings, not against the Required switches of the Elementor form.
4. Remove the action **Email** from the form, unless you want it. See "What it costs" below.
5. Save the page and send a test request.

### How it behaves

- Errors of the plugin's checks appear beside the Elementor field with that ID, in the same request in which the visitor sends the form. An error for a field the form does not have (for example the consent, when the form has no `consent` field, or a key that code on [`quote_requests_validate`](hooks.md#quote_requests_validate) returns) appears as the form's error message.
- The consent record of a request names the Acceptance Text of the `consent` field, because that is the text the visitor ticked. Without such a text it names the consent text of the settings. The privacy page in the record is the one of the settings.
- The page the form was on is stored as "Sent from page" with the visitor details of the request: the page address Elementor recorded for the submission, or else the one its script sends with the form.
- A request the plugin refuses, also for the rate limit, ends there: Elementor runs none of the form's actions for it, so it sends no email and keeps no submission.
- A request that passes is stored once, when Elementor runs the action, with the source label `elementor`.
- After a sent form the script empties the visitor's quote list: Elementor announces a sent form in the browser, and the plugin listens for that on forms that hold the hidden field.
- The reference of the stored request is in the answer of the form. A script that listens to Elementor's `submit_success` event finds it in the second argument, as `data.quote_requests.ref`.
- Elementor's own spam protection (honeypot, reCAPTCHA, Akismet) runs before the plugin's check. Add one of them to the form: the plugin's own token and honeypot belong to its built-in form only.

### What it costs

- **Two copies.** Elementor keeps its own copy of each submission under Elementor > Submissions (its "Collect Submissions" action), next to the request under WooCommerce > Quote requests. Remove that action from the form if you want one copy only.
- **Two emails.** Elementor's Email action sends its own email, and the plugin sends the team email (and the customer's copy). Remove the Email action to avoid two emails.
- **Checks run twice.** The plugin checks a submission once before Elementor's actions and once when it stores it. Code on the filter [`quote_requests_validate`](hooks.md#quote_requests_validate) therefore runs twice for one Elementor submission.
- **The order of the actions.** Elementor saves its own copy of the submission first (Collect Submissions). The "Quote request" action runs next. Elementor's Email, Redirect and Webhook actions, and its other actions, come after it. A request is normally refused before any of them, at the check. In the rare case that the "Quote request" action itself refuses a request at that late point (the hourly limit was reached between the check and the action, or the record could not be saved), the form shows an error, but Elementor's copy of the submission exists and its other actions still run: its Email is sent and its Webhook is called for a request that was not stored.

## An adapter for another form plugin

This is everything an adapter needs: one function that hands a submission to the plugin, called from the hook of your form plugin. The form has the fields `name`, `phone`, `email`, `company`, `region`, `message` and `consent`, and the hidden input `quote_requests_items`. Put the text of your form's consent tick in `consent_text`.

```php
/**
 * Stores a submission of your form as a quote request.
 *
 * @param array $posted The submitted values by field name, without slashes.
 * @return array ok (bool), message (string, for the whole form) and errors (messages by field name).
 */
function my_site_quote_from_form( array $posted ): array {
    if ( ! function_exists( 'quote_requests_submit' ) ) {
        return array( 'ok' => false, 'message' => 'Quote requests are not available.', 'errors' => array() );
    }

    $fields = array();
    foreach ( array( 'name', 'phone', 'email', 'company', 'region', 'message', 'consent' ) as $key ) {
        if ( isset( $posted[ $key ] ) && is_scalar( $posted[ $key ] ) ) {
            $fields[ $key ] = (string) $posted[ $key ];
        }
    }
    $items  = quote_requests_parse_items( $posted['quote_requests_items'] ?? '' );
    $args   = array(
        'source'       => 'my-form',
        // The text beside the consent tick of your form, so the record shows what the visitor agreed to.
        'consent_text' => 'I agree that my details are used to answer my request.',
    );
    $answer = quote_requests_submit( $fields, $items, $args );

    if ( $answer['ok'] ) {
        return array( 'ok' => true, 'message' => sprintf( 'Thank you. Your reference is %s.', $answer['ref'] ), 'errors' => array() );
    }
    return array( 'ok' => false, 'message' => $answer['message'], 'errors' => $answer['errors'] );
}

// YOUR FORM PLUGIN'S HOOK GOES HERE. Replace 'your_form_plugin_submitted' with the hook your form plugin fires
// for an accepted submission, and read the values the way that hook hands them over. That hook must fire on
// wp_loaded or later, or on init with a priority above 10: earlier, the answer is "not ready" and nothing is stored.
add_action(
    'your_form_plugin_submitted',
    static function ( array $posted ): void {
        $result = my_site_quote_from_form( $posted );
        // Hand $result back with your form plugin's own functions: $result['errors'] beside the fields,
        // $result['message'] for the form. When $result['ok'] is true, let the page fire quote_requests:sent.
        do_action( 'my_site_quote_form_result', $result, $posted );
    }
);
```

What is left to you is what only your form plugin knows: the name of its hook, how it hands over the values, and how it shows errors. Four things to keep in mind:

- Spam protection is the job of your form. The function does not check a token or a honeypot.
- The hook must not fire too early. See [When to call it](#when-to-call-it). A refusal, also the one with the code `not_ready`, comes back as `ok` false with a message, which the example hands to the form.
- The messages are plain text. Escape them where you print them.
- Fire `quote_requests:sent` in the browser after a stored request, as described above.

## Not supported

- **The Elementor V4 atomic form** (the form built from atomic elements, an Elementor experiment). It is a separate module of Elementor Pro with hooks of its own, and it fires none of the three hooks of the Form widget: `elementor_pro/forms/actions/register`, `elementor_pro/forms/validation` and `elementor_pro/forms/process`. The action is built on the first two. In Elementor Pro 4.3.1 that module (`modules/atomic-form`) has seven hooks, all under `elementor_pro/atomic_forms/`: `actions/register`, `spam_check`, `form_submitted`, `email_headers`, `email_message`, `webhooks/request_args` and `action_log_label`. None of them lets code check a submission and return errors beside the fields, and its actions have another shape (`execute()` with the form data, instead of `run()` with a record). So the "Quote request" action is not offered there. Use the classic Form widget.
- **Other form plugins** have no ready-made action. Write an adapter as shown above.
- **File uploads.** A quote request holds text values and products. A file field of your form is not part of it.
- **A form without JavaScript** cannot carry the quote list, because the list is written into the hidden input by the script. The request is then sent without products.
