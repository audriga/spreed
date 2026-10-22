# Universal Talk Sharing API

**Location:** `apps/spreed`  
**Bundle:** `talk-share.js` (compiled from plain JavaScript `src/share.js`)  
**Global Function:** `window.OCA.Talk.share(messageType, messagePayload, options)`  

---

## 1. Overview & Intent

The **Universal Talk Sharing API** standardizes sharing content from any Nextcloud app (e.g. Deck, Mail, Calendar, Notes, Files, or custom third-party apps) into Nextcloud Talk (`spreed`) conversations.

Talk provides an unconditionally available, globally injected frontend helper:
1. **Unconditional Global Availability**: Talk automatically injects `talk-share.js` and `talk-share.css` for all logged-in web sessions. Consuming apps do not need custom PHP plugin loaders or script registrations.
2. **Unified Single-Method Contract**: Consuming apps call `window.OCA.Talk.share(messageType, messagePayload, options)`.
3. **Two Operating Modes**:
   - **Fire and Forget (`redirect: false`)**: Shares content in-place without leaving the current app. Launches Talk's native conversation picker dialog or posts directly if `roomToken` is supplied, then displays a success toast with a link to the message.
   - **Fire and Switch (`redirect: true`)**: Stages `{ messageType, messagePayload }` in browser `sessionStorage`, navigates to `/apps/spreed/sendto`, un-stages the payload, executes the post (or prompts with the conversation picker), and lands the user directly in the active chat.
4. **Three Message Types**:
   - `text`: Plain text chat comments and URLs (Talk automatically detects URLs and unfurls rich link previews).
   - `json-ld`: Generic structured Schema.org data (single objects or arrays) rendered via Talk's interactive card system on patched servers.
   - `file`: Files located in the user's Nextcloud storage shared directly into the conversation.

---

## 2. Implementation Summary

### Backend (`apps/spreed/lib/`)
* **`Share\SharePluginLoader.php`**: Listens for `OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent`. When a user is logged in, it registers `talk-share.js` and `talk-share.css` via `Util::addScript` and `Util::addStyle`.
* **`AppInfo\Application.php`**: Registers `SharePluginLoader` in `$context->registerEventListener`.
* **`Controller\PageController.php`**: Exposes `/apps/spreed/sendto` and `/apps/spreed/sendto/{token}` routes using `#[FrontpageRoute]` attributes, serving Talk's SPA template.
* **`Capabilities.php`**: Dynamically checks `(new \OCP\RichObjectStrings\Definitions())->definitions['json-ld-card']`. Only if Nextcloud server core's `Definitions.php` defines `json-ld-card` does Talk advertise this feature, guaranteeing stability across patched and unpatched servers.

### Frontend (`apps/spreed/src/`)
* **`share.js` (plain JavaScript, entry point in `rspack.config.js`)**:
  - Compiles to `talk-share.js`.
  - Exposes `window.OCA.Talk.share(messageType, messagePayload, options)`.
  - Enforces frontend contract validation with `console.warn` before network requests.
  - Generically maps JSON-LD objects and arrays, safely extracting title and description using optional chaining (`?.`).
  - Directly reuses Talk's native `requestRoomSelection.js` and `RoomSelector.vue` without modification.
  - Implements chat comment posting (`postNewMessage`), JSON-LD card posting (`postRichObjectToConversation`), and file sharing (`shareFile`).
* **`router/router.ts`**: Adds the `/apps/spreed/sendto/:token?` route.
* **`App.vue` (`handleSendTo`)**:
  - Handles landing on `/apps/spreed/sendto`.
  - Reads `itemid` parameter, un-stages `{ messageType, messagePayload }`, and deletes the key immediately from `sessionStorage` to prevent replay on refresh.
  - If `roomToken` is provided: posts immediately and navigates to `/call/{token}`.
  - If `roomToken` is omitted: launches `requestRoomSelection`, posts upon selection, and navigates to `/call/{selectedToken}`.

---

## 3. Usage Guide & Developer README

### 3.1 Availability & Capability Checks

```js
import { getCapabilities } from '@nextcloud/capabilities'

// 1. Check if Talk (spreed) is installed and enabled for the current user
export function isTalkAvailable() {
    const caps = getCapabilities()
    return Boolean(caps?.spreed)
}

// 2. Check if the server supports JSON-LD interactive cards
export function isJsonLdSupported() {
    const caps = getCapabilities()
    const features = caps?.spreed?.features || []
    return features.includes('json-ld-card')
}

// 3. Optional frontend runtime check: verify if the global API is loaded
export function isShareApiLoaded() {
    return typeof window.OCA?.Talk?.share === 'function'
}
```

---

### 3.2 API Contract

```js
window.OCA.Talk.share(messageType, messagePayload, options)
```

#### Parameters:

1. **`messageType`** (`string`, optional, default: `'text'`):
   - `'text'`: Plain text or URL.
   - `'json-ld'`: Schema.org JSON-LD structured data (object, array, or JSON string).
   - `'file'`: Path to a file in Nextcloud storage (e.g. `'/Documents/report.pdf'`).
   *(Convenience: calling `share('Hello world')` with a single argument automatically defaults to `('text', 'Hello world')`).*

2. **`messagePayload`** (`string` | `object` | `array`):
   - For `'text'`: non-empty string.
   - For `'file'`: non-empty string representing the file path.
   - For `'json-ld'`: an object (e.g. `{ "@context": "https://schema.org", "@type": "Recipe", ... }`), an array of objects (`[ { ... } ]`), or a valid JSON string.

3. **`options`** (`object`, optional):
   - `redirect` (`boolean`, default: `false`):
     - `false`: In-place share (fire and forget). Opens room picker dialog if no `roomToken` is supplied; does not navigate away.
     - `true`: Redirect share (fire and switch). Navigates the browser to Talk (`/apps/spreed/sendto`).
   - `roomToken` (`string`, optional): Target conversation token. If provided, skips the dialog and posts directly.
   - `title` (`string`, optional, default: `'Share to conversation'`): Dialog title for the conversation picker.
   - `showToast` (`boolean`, optional, default: `true`): Whether Talk should display global `@nextcloud/dialogs` toasts on success or error. Set to `false` for silent execution if the caller manages its own UI notifications.

#### Return Value (Result Object Pattern):
To shield consuming applications from unhandled promise rejections, `window.OCA.Talk.share` **always resolves** and never throws. It returns a structured result object:
- **Success**: `{ success: true, token: string, messageId?: number, targetUrl: string }`
- **User Canceled Picker**: `{ success: false, canceled: true }`
- **Validation or API Error**: `{ success: false, error: string }`
- **Redirect Mode**: `{ success: true, redirecting: true }`

---

### 3.3 Generic JSON-LD Handling

When sharing JSON-LD data (`messageType: 'json-ld'`), the helper automatically extracts metadata from the payload:
- **Title (`name`)**: `payload?.name || payload?.headline || payload?.['@type'] || 'JSON-LD Data'`
- **Description (`description`)**: `payload?.description || payload?.articleBody || ''`
- **Array Payloads**: If an array is passed (e.g. `[ { "@type": "Recipe", ... }, ... ]`), the root metadata is extracted from the first element (`payload[0]`), while the complete array is forwarded in full to the server.
- **Card Identifier**: A unique ID is generated automatically for the rich object instance on Talk.

---

### 3.4 Usage Examples

#### 1. Share Plain Text or URL (with Dialog)
```js
await window.OCA.Talk.share('text', 'Hello team! Check out this project update.')
```
Or using the single-argument convenience:
```js
await window.OCA.Talk.share('https://nextcloud.com')
```

#### 2. Share Directly to a Known Conversation
```js
await window.OCA.Talk.share('text', 'Task completed.', {
    roomToken: 'kwmp62zv',
})
```

#### 3. Share a JSON-LD Object (e.g. Recipe, Event, Person)
```js
const recipe = {
    '@context': 'https://schema.org',
    '@type': 'Recipe',
    name: 'Grandma’s Pancake Recipe',
    description: 'Fluffy homemade pancakes with maple syrup.',
    recipeYield: '4 servings',
    recipeIngredient: ['1 1/2 cups flour', '1 egg', '1 cup milk'],
}

await window.OCA.Talk.share('json-ld', recipe)
```

#### 4. Share a JSON-LD Array
```js
const entities = [
    {
        '@context': 'https://schema.org',
        '@type': 'Event',
        name: 'Conference 2026',
        description: 'Annual gathering',
    },
    {
        '@context': 'https://schema.org',
        '@type': 'Place',
        name: 'Main Auditorium',
    },
]

await window.OCA.Talk.share('json-ld', entities)
```

#### 5. Handling the Result Object (Optional State / UI Handling)
For callers that want to inspect the outcome or execute follow-up logic:
```js
const res = await window.OCA.Talk.share('text', 'Hello')

if (res.success) {
    this.closeMyModal()
    console.log(`Message #${res.messageId} posted to conversation ${res.token}`)
} else if (res.canceled) {
    console.log('User dismissed conversation picker')
} else {
    console.warn('Share could not be completed:', res.error)
}
```

#### 6. Share a File from Nextcloud Storage
```js
await window.OCA.Talk.share('file', '/Documents/Quarterly-Report.pdf', {
    roomToken: 'kwmp62zv',
})
```

#### 7. Fire and Switch (Redirect into Talk)
```js
window.OCA.Talk.share('json-ld', recipe, {
    redirect: true,
})
// Or directly into a specific chat:
window.OCA.Talk.share('json-ld', recipe, {
    redirect: true,
    roomToken: 'kwmp62zv',
})
```

---

### 3.5 Error Handling & Shielding

Because `share()` uses the Result Object pattern, **it never throws an unhandled promise rejection**:
- **Toast & Log**: If something goes wrong (validation mismatch, missing permissions, server error), Talk automatically logs `console.warn(...)` and displays an informative user toast (unless disabled via `showToast: false`).
- **Return Value**: The function returns `{ success: false, error: '...' }` (or `{ success: false, canceled: true }` if canceled). Callers can safely fire-and-forget without `try / catch` boilerplate, or optionally check `res.success` if they need to update local state.

---

### 3.6 Reference Testbed

A reference implementation exercising all combinations and validation states is located in [apps/app_template/src/App.vue](/home/lukas/Documents/servers/nextcloud-master/apps/app_template/src/App.vue).

---

## 4. Limitations

1. **Storage-resident files only**: `messageType: 'file'` expects an existing file path in Nextcloud storage; it does not handle raw browser file uploads (`File`/`Blob` objects).
2. **SessionStorage payload limits**: In `redirect: true` mode, browser `sessionStorage` has a ~5MB origin limit (avoid megabyte-scale JSON-LD attachments).
3. **Authenticated session required**: `talk-share.js` is only injected for logged-in user sessions, not for public or unauthenticated guest shares.
4. **Patched server requirement for rich JSON-LD**: Interactive card rendering depends on the server defining `json-ld-card` in `Definitions.php` and Talk containing `JsonLdCard.vue`. On unpatched servers, JSON-LD sharing cleanly returns an unsupported error.
5. **Localization context**: Default dialog titles and toasts use Talk's translation catalog (`t('spreed', ...)`). Override the dialog title via `options.title` if custom app wording is desired.
