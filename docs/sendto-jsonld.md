# Nextcloud Talk "SendTo" Pattern (JSON-LD)

**Location:** `apps/spreed`  
**Endpoint:** `/apps/spreed/sendto` (supports both `POST` and `GET`)  
**Target Capabilities:** `spreed.features['json-ld-card']`  

---

## 1. Overview & Architecture

The **SendTo pattern** provides a standardized, clean mechanism for any Nextcloud app (e.g. Deck, Mail, Cookbook, Calendar, Notes, or custom apps) to share structured Schema.org JSON-LD cards into Nextcloud Talk (`spreed`) conversations.

It mirrors the architecture used across Nextcloud apps (like Mail's compose pattern), avoiding URL length limits and eliminating the need for apps to inject custom JavaScript bundles or dialog scripts into other apps' pages.

The pattern supports two primary workflows on the exact same base path (`/apps/spreed/sendto`):

1. **Fire & Forget (`POST /apps/spreed/sendto`)**:  
   The consuming app makes a direct HTTP POST request to post a `json-ld-card` message into a designated conversation without leaving the current screen.
2. **Fire & Switch (`sessionStorage` + `GET /apps/spreed/sendto`)**:  
   The consuming app writes the JSON-LD payload to browser `sessionStorage` and navigates the user via a clean GET request. Talk boots, retrieves the payload using `InitialStateService`, and lands the user directly in the active conversation (or launches Talk's room selector if no room was specified).

---

## 2. Implementation Summary

### Backend (`apps/spreed/lib/Controller/PageController.php`)
Both HTTP verbs are handled by `PageController`:

* **`POST /apps/spreed/sendto` (`PageController::sendToPost`)**:
  - Accepts a JSON body containing `token` (target room token) and `jsonld` (Schema.org JSON-LD object or string).
  - Automatically derives the card title and description from the JSON-LD payload itself.
  - Validates user authentication, room membership, and room write permissions.
  - Generates the `json-ld-card` rich object message and posts it into the room.
  - Returns a direct JSON response with HTTP 201: `{"id": 131, "token": "kwmp62zv"}`.

* **`GET /apps/spreed/sendto` and `GET /apps/spreed/sendto/{token}` (`PageController::sendTo` / `sendToToken`)**:
  - Accepts query parameter `itemid` (the key used in browser `sessionStorage`).
  - Injects `sendto_item_id` and `sendto_token` into Nextcloud's `IInitialStateService` (`spreed` domain) so route state survives client-side routing.
  - Returns the standard Talk web application template (`TemplateResponse`).

### Capability Reporting (`apps/spreed/lib/Capabilities.php`)
Talk dynamically queries Nextcloud core's `\OCP\RichObjectStrings\Definitions`. Only when `json-ld-card` is registered in core definitions does Talk advertise `'json-ld-card'` in `capabilities.spreed.features`.

### Frontend Reception (`apps/spreed/src/App.vue`)
On application mount, Talk's root component executes `checkPendingShare()`:
1. Reads `sendto_item_id` and `sendto_token` from `loadState('spreed', ...)` (with URL query fallback).
2. Retrieves the staged payload from `sessionStorage.getItem(sendtoItemId)`.
3. Immediately removes the item from `sessionStorage` via `sessionStorage.removeItem(sendtoItemId)` to prevent duplicate sends on subsequent page reloads.
4. **Target Room Resolution**:
   - If a conversation token was provided: posts the rich card directly via Talk's native `postRichObjectToConversation` service.
   - If no conversation token was provided: opens Talk's native conversation picker dialog (`requestRoomSelection`), posts the card upon user selection, and transitions to `/call/{selectedToken}`.

---

## 3. Developer Guide & Usage Examples

### 3.1 Capability Detection

Before offering JSON-LD sharing into Talk, consuming apps should verify capability:

```ts
import { getCapabilities } from '@nextcloud/capabilities'

// 1. Verify Talk is installed and enabled for the current user
export function isTalkAvailable(): boolean {
    const caps = getCapabilities()
    return Boolean(caps?.spreed)
}

// 2. Verify Talk supports interactive JSON-LD rich cards
export function isJsonLdSupported(): boolean {
    const caps = getCapabilities()
    const features = (caps?.spreed as { features?: string[] })?.features || []
    return features.includes('json-ld-card')
}
```

---

### 3.2 Pattern 1: Fire & Forget (Direct POST)

Post directly into a known conversation without navigating away:

```ts
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const recipe = {
    '@context': 'https://schema.org',
    '@type': 'Recipe',
    name: 'Grandma’s Pancake Recipe',
    description: 'Fluffy homemade pancakes with maple syrup.',
    recipeYield: '4 servings',
}

const response = await axios.post(generateUrl('/apps/spreed/sendto'), {
    token: 'kwmp62zv', // Target conversation token
    jsonld: recipe,
})

console.log('Message created with ID:', response.data.id)
```

**HTTP Request Details:**
```http
POST /index.php/apps/spreed/sendto
Content-Type: application/json

{
  "token": "kwmp62zv",
  "jsonld": {
    "@context": "https://schema.org",
    "@type": "Recipe",
    "name": "Grandma’s Pancake Recipe",
    "description": "Fluffy homemade pancakes with maple syrup."
  }
}
```

**HTTP Response (HTTP 201 Created):**
```json
{
  "id": 131,
  "token": "kwmp62zv",
  "messageType": "comment",
  "message": "{object}",
  "messageParameters": {
    "object": {
      "type": "json-ld-card",
      "id": "1792770857-8045983b",
      "name": "Grandma’s Pancake Recipe",
      "description": "Fluffy homemade pancakes with maple syrup.",
      "jsonld": "..."
    }
  }
}
```

---

### 3.3 Pattern 2: Fire & Switch (SessionStorage + GET Redirect)

Stage the payload in browser `sessionStorage` and redirect into Talk:

#### A. Redirecting directly to a known conversation:
```ts
import { generateUrl } from '@nextcloud/router'

const itemId = Date.now().toString(36)
const token = 'kwmp62zv' // Target conversation token

// 1. Stage in sessionStorage (using unprefixed itemId directly as the key)
sessionStorage.setItem(itemId, JSON.stringify({
    jsonld: recipeObject,
}))

// 2. Redirect
window.location.href = generateUrl(`/apps/spreed/sendto/${token}?itemid=${itemId}`)
```

#### B. Redirecting without a conversation (prompts user with Talk's room selector):
```ts
import { generateUrl } from '@nextcloud/router'

const itemId = Date.now().toString(36)

// 1. Stage in sessionStorage (using unprefixed itemId directly as the key)
sessionStorage.setItem(itemId, JSON.stringify({
    jsonld: recipeObject,
}))

// 2. Redirect to Talk picker
window.location.href = generateUrl(`/apps/spreed/sendto?itemid=${itemId}`)
```

---

## 4. Reference Testbed

A complete interactive implementation exercising both the direct POST and redirect flows is located in:  
[apps/app_template/src/App.vue](/home/lukas/Documents/servers/nextcloud-master/apps/app_template/src/App.vue)

---

## 5. Trade-offs and Limitations

- **Controller Architecture Placement**: The data-modifying endpoint (`POST /apps/spreed/sendto`) is implemented directly in `PageController` rather than a dedicated OCS controller (`ocs/v2.php`). This intentionally trades Nextcloud's conventional strict view/data controller separation in favor of a single, clean URL (`/apps/spreed/sendto`) shared across both GET and POST.
- **SessionStorage Scope**: Staged payloads in `sessionStorage` are scoped to the current browser tab; opening redirect links in a new tab will not have access to the staged payload.
