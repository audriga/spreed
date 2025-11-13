<!--
  - SPDX-FileCopyrightText: 2025
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<template>
	<div class="schema">
		<div v-html="html"></div>
	</div>
</template>

<script>
import Jsonld2html from '../../../../../../node_modules/jsonld2html-cards/jsonld2html-bundle.js'

export default {
	name: 'JsonLdCard', // still called DeckCard for substitution
	props:{
		name: {
			type: String,
			required: true,
		},
		description: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			html: "",
			// Hardcoded test JSON object
			json: {
				"@context": "https://schema.org",
				"@type": "Person",
				"name": this.name,
				"description": this.description,
				"image": "https://upload.wikimedia.org/wikipedia/commons/thumb/a/a4/Ada_Lovelace_portrait.jpg/440px-Ada_Lovelace_portrait.jpg",
				"url": "https://en.wikipedia.org/wiki/Ada_Lovelace",
				"potentialAction": [
					{
					"@type": "ConfirmAction",
					"target": "{{cancelLink}}"
					},
					{
					"@type": "ConfirmAction",
					"target": "{{approvalLink}}"
					}
				]
			}
		}
	},
	created() {
		this.getRenderedSchema()
	},
	methods: {
		getRenderedSchema() {
			const rendered = Jsonld2html.render(this.json)
			this.html = rendered
			return rendered
		}
	}
}
</script>


<style scoped> .schema { /* Box surrounding the displayed card and possible actions. */ display: flex; width: fit-content; flex-direction: column; margin: 50px; border: 2px none var(--color-border); border-radius: 16px; padding: 10px; align-items: left; box-shadow: 0px 0px 10px 0px var(--color-box-shadow); } .full-schema { /* Useful for debugging the component. */ display: none; font-size: x-small; opacity: 0.4; font-weight: lighter; } /* Fix using Vue 3 deep combinator ::v-deep */ .schema ::v-deep(.smlCard) { max-width: 600px; display: flex; flex-direction: column; gap: 5px; /* round corners*/ border: 2px solid var(--color-border); border-radius: 6px; /* padding in the card*/ padding: 20px; background: var(--color-main-background); } .schema ::v-deep(.smlCard .header) { /* create a bottom border from left to right */ border-block-end: 2px solid var(--color-border); margin: -10px -20px 0px -20px; padding-bottom: 10px; /* add spacing before text */ text-indent: 20px; font-size: 20px; font-weight: bold; color: var(--color-main-text); } .schema ::v-deep(.smlCardRow) { /* Layout Settings */ display: flex; flex-direction: row; flex-wrap: nowrap; justify-content: flex-start; } .schema ::v-deep(.smlCardRow .text_column) { display: flex; flex-direction: column; flex-wrap: nowrap; justify-content: flex-start; align-items: flex-start; min-height: 100px; max-height: 150px; flex-basis: 90%; min-width: 0; margin-left: 20px; } .schema ::v-deep(.smlCardRow .card_title) { margin: 4px 0px; font-size: 20px; font-weight: bold; min-height: 20%; color: var(--color-main-text); /* settings for truncating single line text */ max-width: 95%; text-overflow: ellipsis; white-space: nowrap; overflow-x: auto; } .schema ::v-deep(.smlCardRow .card_content) { margin-top: 4px; margin-bottom: 4px; font-size: 16px; color: var(--color-main-text); /* this is for truncating multiline texts */ display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 4; overflow: auto; } .schema ::v-deep(.smlCardRow .image_column) { display: flex; align-items: center; justify-content: center; min-height: 100px; min-width: 100px; max-width: 100px; overflow: hidden; } .schema ::v-deep(.smlCardRow img) { display: block; max-width: 100px; max-height: 100px; min-width: 100px; } .schema ::v-deep(br) { display: none; } </style>

