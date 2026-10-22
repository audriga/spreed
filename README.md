# Nextcloud Talk (SML Edition)

This is a patched edition of Nextcloud Talk (`spreed`) featuring native support for structured **JSON-LD messages** (rendered interactively via Linked Data Web Components) and the **Universal Talk Sharing API** for seamless sharing from any Nextcloud app.

---

## Installation

### 1. Disable existing Talk app
If an official Talk app is currently active, disable it in Nextcloud under **Apps** &rarr; **Active apps**.

### 2. Register `json-ld-card` in Nextcloud Server
On your Nextcloud server instance, add the `json-ld-card` rich object definition:
* **Target file:** `lib/public/RichObjectStrings/Definitions.php`
* **Target array:** `public $definitions = [ ... ]`
* **Definition to insert:**

```php
		'json-ld-card' => [
			'author' => 'audriga',
			'app' => 'ncsd',
			'since' => '20.0.0',
			'parameters' => [
				'id' => [
					'since' => '29.0.0',
					'required' => true,
					'description' => 'The id used to identify the card on the instance',
					'example' => '1',
				],
				'name' => [
					'since' => '29.0.0',
					'required' => true,
					'description' => 'The title of the card',
					'example' => 'Card Title 1',
				],
				'description' => [
					'since' => '29.0.0',
					'required' => true,
					'description' => 'The text describing the object',
					'example' => 'Personal',
				],
				'jsonld' => [
					'since' => '29.0.0',
					'required' => true,
					'description' => 'the actual json-ld object',
					'example' => '{
						"@context": "https://schema.org",
						"@type": "Person",
						"name": "Birdie Bird",
						"description": "this is some description",
						"image": "https://upload.wikimedia.org/wikipedia/commons/thumb/a/a4/Ada_Lovelace_portrait.jpg/440px-Ada_Lovelace_portrait.jpg",
						"url": "https://en.wikipedia.org/wiki/Ada_Lovelace"
					}',
				],
			],
		],
```

### 3. Clone and checkout the development branch
```bash
git clone https://github.com/audriga/spreed.git
cd spreed
git checkout dev
```

### 4. Install dependencies
```bash
composer install
npm install
```

### 5. Build frontend assets
```bash
npm run build
# Or for local development with source maps:
npm run dev
```

### 6. Deploy app code
Copy the app directory into your Nextcloud installation:
```bash
# Target: <nextcloud-installation>/apps/spreed
cp -r . /path/to/nextcloud/apps/spreed/
```
*(You can omit `node_modules` and `vendor-bin` if copying an already built release).*

### 7. Enable the app
1. Log in as an administrator.
2. Navigate to **Apps** (top-right user menu &rarr; **Apps**).
3. Select **Disabled apps** in the left sidebar.
4. Locate **Talk** and click **Enable**.

---

## Features

### JSON-LD Message Types
Enables posting arbitrary Schema.org JSON-LD structured objects into Talk conversations via Talk's OCS share endpoint (`objectType=json-ld-card`).

#### Quick start (cURL example)
```bash
curl -X POST -u admin:admin -H "OCS-APIRequest: true" \
  "http://localhost/index.php/ocs/v2.php/apps/spreed/api/v1/chat/<roomToken>/share" \
  -d "objectType=json-ld-card" \
  -d "objectId=card-101" \
  -d "metaData={\"id\":\"card-101\",\"name\":\"Birdie Bird\",\"description\":\"Sample Contact Card\",\"jsonld\":\"{\\\"@context\\\": \\\"https://schema.org\\\", \\\"@type\\\": \\\"Person\\\", \\\"name\\\": \\\"Birdie Bird\\\", \\\"description\\\": \\\"Sample contact description\\\"}\"}"
```

### JSON-LD Message Rendering
JSON-LD messages are rendered inline in the chat using **LDWC** (`<type-renderer>`). This provides interactive, rich visualization for Schema.org entities (such as Recipes, Events, Persons, and interactive Schema.org Actions).

### Universal Talk Sharing API
Provides a standardized client-side entry point for any Nextcloud app (Deck, Mail, Notes, Calendar, or third-party apps) to share text, files, and rich JSON-LD data into Talk conversations without app-specific backend coupling.

* Supports both **in-place** sharing (with a conversation picker dialog) and **redirect** mode (`sendto`).
* Full specification and developer guide: [Universal Talk Sharing Documentation](docs/talk-share.md).

#### Quick start (JavaScript)
```js
// Share a Schema.org object directly from any app's frontend
await window.OCA.Talk.share('json-ld', {
    '@context': 'https://schema.org',
    '@type': 'Recipe',
    name: 'Pancake Recipe',
    description: 'Fluffy homemade pancakes',
})
```

---

## Dependencies
* json-ld-web-components (LDWC) Web components for rendering Schema.org structured data.