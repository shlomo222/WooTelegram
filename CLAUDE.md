# WooTelegram Manager — Claude Code Rules

## מה זה
תוסף WordPress שמאפשר לנהל חנות WooCommerce דרך בוט טלגרם.
MVP: העלאת מוצרים חדשים (כולל וריאציות ותמונות) מטלגרם.
שלב 2: ניהול הזמנות, מלאי, משלוחים, קופונים.
שלב 3: חיבור ל-AI לעריכת תמונות ויצירת תיאורים.
שלב עתידי: הפיכה ל-SaaS multi-tenant.

## Stack
- PHP 8.0+
- WordPress 6.0+
- WooCommerce 8.0+
- Telegram Bot API (Webhook, לא Polling)
- MySQL (דרך $wpdb — WordPress native)
- אין Composer. אין NPM. אין Build step. אין dependencies חיצוניות.

## מבנה התוסף
```
woo-telegram-manager/
├── woo-telegram-manager.php        ← Main plugin file (header + activation)
├── uninstall.php                   ← Cleanup on uninstall
├── readme.txt                      ← WordPress readme (יווצר ב-Phase 6)
├── CLAUDE.md                       ← הקובץ הזה
├── includes/
│   ├── class-wootg-loader.php      ← Autoloader + main init
│   ├── class-wootg-installer.php   ← DB tables creation
│   ├── class-wootg-settings.php    ← Admin settings page
│   ├── class-wootg-crypto.php      ← Encrypt/decrypt tokens
│   ├── class-wootg-telegram.php    ← Telegram API wrapper
│   ├── class-wootg-webhook.php     ← REST endpoint /wootg/v1/webhook/<secret>
│   ├── class-wootg-router.php      ← Routes incoming messages to flows
│   ├── class-wootg-session.php     ← Session state management
│   ├── class-wootg-logger.php      ← Activity log
│   ├── class-wootg-auth.php        ← Is user authorized?
│   └── flows/
│       ├── class-wootg-flow-base.php
│       ├── class-wootg-flow-main-menu.php
│       └── class-wootg-flow-add-product.php
├── admin/
│   ├── settings-page.php            ← Admin UI template
│   └── assets/
│       ├── admin.css
│       └── admin.js
└── languages/
    └── woo-telegram-manager-he_IL.po
```

## חוקי זהב — קרא לפני כל session
1. **Prefix הכל ב-`wootg_`** — פונקציות, hooks, options, transients. WordPress חולק namespace גלובלי.
2. **כל class מתחיל ב-`WooTG_`** — בדיוק ככה, לא `WT`, לא `Wootg`.
3. **תמיד sanitize input, תמיד escape output**:
   - Input: `sanitize_text_field()`, `absint()`, `wp_kses_post()`, `sanitize_email()`
   - Output: `esc_html()`, `esc_attr()`, `esc_url()`
4. **Nonces לכל AJAX וטופס באדמין**: `wp_create_nonce('wootg_action')` + `check_admin_referer()` / `check_ajax_referer()`
5. **Webhook secret ב-URL**: `/wp-json/wootg/v1/webhook/<secret>` — ההגנה העיקרית מקריאות מזויפות. המזהה הזה חייב להיות רנדומלי (wp_generate_password(32, false)) ונשמר ב-wp_options.
6. **SSL חובה**: Telegram לא ירשום webhook בלי HTTPS. אם לוקל → ngrok.
7. **State ב-DB, לא ב-PHP sessions**: REST endpoints stateless, אסור להסתמך על $_SESSION.
8. **כל קריאה ל-Telegram API → try/catch + log**: הבוט לא יכול להיתקע בלי שתדע.
9. **Multi-tenant ready**: כל query כולל `site_id`. ב-MVP תמיד 1, אבל אל תדלג על העמודה בשום טבלה.
10. **Never echo — return**: פונקציות שמרכיבות HTML מחזירות string, לא echo. נותן לנו שליטה.
11. **תרגומים**: `__('טקסט', 'woo-telegram-manager')` או `_e()` / `esc_html__()`. Text domain תמיד `'woo-telegram-manager'`.
12. **אם WooCommerce לא פעיל** → deactivate + admin notice. אל תקרוס.
13. **Defensive coding**: `if ( ! class_exists('WC_Product_Simple') ) return;` לפני כל שימוש ב-WC.
14. **ABSPATH check**: כל קובץ PHP מתחיל ב-`if ( ! defined('ABSPATH') ) exit;`
15. **Commit אחרי כל פיצ'ר עובד**. לא מצטברים שינויים.

## Telegram API Flow
```
Telegram → POST → https://site.com/wp-json/wootg/v1/webhook/<SECRET>
  ↓
WooTG_Webhook::handle() receives update
  ↓
WooTG_Auth::is_authorized(chat_id) — מורשה?
  ↓
WooTG_Router::route(update) — איזה flow פעיל?
  ↓
Flow class handles step:
  - WooTG_Session::get(chat_id) — מה המצב?
  - processes input (text / photo / callback_query)
  - WooTG_Session::update(chat_id, new_data)
  - WooTG_Telegram::send_message(...) — שולח תשובה
  ↓
Return 200 OK to Telegram
```

## Telegram Update Types שצריך לתמוך
- `message.text` — טקסט רגיל
- `message.photo` — תמונה (לוקח את הגרסה הכי גדולה)
- `callback_query` — לחיצה על כפתור inline
- `message.document` — אופציונלי, קבצים
- פקודות: `/start`, `/menu`, `/cancel`, `/help`

## שלבי MVP
- **Phase 1**: תשתית + Admin Settings (אנחנו עכשיו כאן)
- **Phase 2**: Webhook + Auth + Main Menu
- **Phase 3**: Add Product flow — טקסטים (name, description, price, category, stock)
- **Phase 4**: Add Product flow — תמונות
- **Phase 5**: Add Product flow — וריאציות
- **Phase 6**: בדיקות + תרגום + readme + release v1.0.0

## WooCommerce API Reference
תמיד ישירות דרך WC_Product classes, לא REST.

```php
// מוצר פשוט
$product = new WC_Product_Simple();
$product->set_name('שם מוצר');
$product->set_regular_price('99.90');
$product->set_sale_price('79.90'); // אופציונלי
$product->set_description('תיאור מלא');
$product->set_short_description('תיאור קצר');
$product->set_status('publish'); // או 'draft'
$product->set_category_ids([12, 15]);
$product->set_image_id($attachment_id);
$product->set_gallery_image_ids([$id1, $id2]);
$product->set_manage_stock(true);
$product->set_stock_quantity(10);
$product->set_stock_status('instock');
$product_id = $product->save();

// מוצר עם וריאציות
$product = new WC_Product_Variable();
$product->set_name('שם');
$product->save();

// Attribute
$attribute = new WC_Product_Attribute();
$attribute->set_id(0); // 0 = custom attribute, לא taxonomy
$attribute->set_name('גודל');
$attribute->set_options(['S', 'M', 'L']);
$attribute->set_visible(true);
$attribute->set_variation(true);
$product->set_attributes([$attribute]);
$product->save();

// Variation
$variation = new WC_Product_Variation();
$variation->set_parent_id($product->get_id());
$variation->set_attributes(['גודל' => 'M']);
$variation->set_regular_price('99');
$variation->set_manage_stock(true);
$variation->set_stock_quantity(5);
$variation->set_image_id($variation_image_id);
$variation->save();

// העלאת תמונה מ-URL של Telegram ל-Media Library
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
$attachment_id = media_sideload_image($telegram_file_url, 0, null, 'id');
```

## Telegram API
```php
// Send message
$response = wp_remote_post("https://api.telegram.org/bot{$token}/sendMessage", [
    'body' => wp_json_encode([
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => $keyboard,
    ]),
    'headers' => ['Content-Type' => 'application/json'],
    'timeout' => 15,
]);

// Inline keyboard
$keyboard = [
    'inline_keyboard' => [
        [
            ['text' => '➕ מוצר חדש', 'callback_data' => 'add_product'],
            ['text' => '📋 מוצרים', 'callback_data' => 'list_products'],
        ],
        [
            ['text' => '🛒 הזמנות', 'callback_data' => 'orders'],
        ],
    ],
];

// Reply keyboard (כפתורים מתחת לשדה ההקלדה)
$keyboard = [
    'keyboard' => [
        [['text' => '➕ מוצר חדש']],
        [['text' => '📋 מוצרים'], ['text' => '🛒 הזמנות']],
    ],
    'resize_keyboard' => true,
    'one_time_keyboard' => false,
];

// Get file URL from file_id (לתמונות)
$info = wp_remote_get("https://api.telegram.org/bot{$token}/getFile?file_id={$file_id}");
$body = json_decode(wp_remote_retrieve_body($info));
$path = $body->result->file_path;
$file_url = "https://api.telegram.org/file/bot{$token}/{$path}";
// עכשיו אפשר media_sideload_image($file_url, 0)
```

## Settings (wp_options)
המפתח `wootg_settings` מכיל:
```json
{
    "bot_token": "encrypted_string",
    "webhook_secret": "random_32_chars",
    "default_product_status": "publish",
    "default_stock_status": "instock",
    "default_manage_stock": true,
    "ai_provider": null,
    "ai_api_key": null
}
```

## Hebrew / RTL
- כל UI באדמין בעברית (עם text domain תקני)
- כל הודעה בבוט בעברית
- Emojis: 📦 מוצר • 🛒 הזמנה • 📊 דוחות • ⚙️ הגדרות • ➕ הוסף • ✏️ ערוך • ✅ אישור • ❌ ביטול • 🔙 חזור

## Error Handling
- Telegram API fail → log + send "שגיאה, נסה שוב" למשתמש
- WC save fail → log WC_Error + send פרטי שגיאה למשתמש
- Session timeout (שעה בלי activity) → איפוס אוטומטי עם הודעה "הזמן עבר, מתחיל מחדש"
- Invalid input (מחיר לא מספר, וכו') → הודעה ברורה + בקש שוב

## Git Workflow
- `main` = stable, מוכן להתקנה
- `develop` = שלב נוכחי בפיתוח
- `feature/phase-N-description` = כל שלב
- Commit message: `feat: X works` / `fix: Y` / `refactor: Z`
- אחרי כל שלב: merge ל-develop, tag ב-main אחרי Phase 6

## Debug Mode
כשה-WP_DEBUG פעיל:
- log כל Telegram update נכנס ב-wp_wootg_activity_log
- log כל Telegram request יוצא
- הצג שגיאות מלאות באדמין

## מה אסור
- לא להשתמש ב-cURL ישיר → רק `wp_remote_post/get`
- לא להשתמש ב-`file_get_contents` עם URL → רק `wp_remote_get`
- לא לשמור סיסמאות / טוקנים ב-plain text
- לא להשתמש ב-`$_POST` / `$_GET` ישיר → רק דרך sanitize helpers
- לא ליצור קבצים שלא בקשתי במפורש
- לא להוסיף UI / הגדרות מעבר למה שהוגדר ב-Phase הנוכחי
