/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCSPNonce } from '@nextcloud/auth'
import { getCapabilities } from '@nextcloud/capabilities'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'
import { generateFilePath, generateUrl } from '@nextcloud/router'
import escapeHtml from 'escape-html'

import { postNewMessage, postRichObjectToConversation } from './services/messagesService.ts'
import { shareFile } from './services/filesSharingServices.ts'
import { requestRoomSelection } from './utils/requestRoomSelection.js'

import '@nextcloud/dialogs/style.css'

/**
 * Check whether the server's Talk installation supports JSON-LD rich card sharing
 *
 * @return {boolean}
 */
export function isJsonLdSupported() {
	try {
		const capabilities = getCapabilities()
		const features = capabilities?.spreed?.features || []
		return features.includes('json-ld-card')
	} catch (e) {
		console.warn('[TalkShare] Failed to inspect Talk capabilities:', e)
		return false
	}
}

/**
 * Validates input parameters for window.OCA.Talk.share
 *
 * @param {string} messageType - 'text' | 'json-ld' | 'file'
 * @param {any} messagePayload - payload data according to messageType
 * @return {{ valid: boolean, error?: string }}
 */
function validateShareInput(messageType, messagePayload) {
	if (!['text', 'json-ld', 'file'].includes(messageType)) {
		return {
			valid: false,
			error: `Unsupported messageType "${messageType}". Expected one of: "text", "json-ld", "file".`,
		}
	}

	if (messagePayload === undefined || messagePayload === null) {
		return {
			valid: false,
			error: 'messagePayload must be provided and cannot be null or undefined.',
		}
	}

	if (messageType === 'text') {
		if (typeof messagePayload !== 'string' || messagePayload.trim().length === 0) {
			return {
				valid: false,
				error: 'messagePayload for "text" must be a non-empty string.',
			}
		}
	} else if (messageType === 'file') {
		if (typeof messagePayload !== 'string' || messagePayload.trim().length === 0) {
			return {
				valid: false,
				error: 'messagePayload for "file" must be a non-empty file path string.',
			}
		}
	} else if (messageType === 'json-ld') {
		if (typeof messagePayload === 'string') {
			try {
				const parsed = JSON.parse(messagePayload)
				if (typeof parsed !== 'object' || parsed === null) {
					return {
						valid: false,
						error: 'messagePayload string for "json-ld" must parse to a valid JSON object or array.',
					}
				}
			} catch (e) {
				return {
					valid: false,
					error: 'messagePayload string for "json-ld" is not valid JSON.',
				}
			}
		} else if (typeof messagePayload !== 'object') {
			return {
				valid: false,
				error: 'messagePayload for "json-ld" must be a JSON object, array, or a valid JSON string.',
			}
		}
	}

	return { valid: true }
}

/**
 * Post a validated payload to a designated conversation token
 *
 * @param {string} token - Conversation token
 * @param {string} messageType - 'text' | 'json-ld' | 'file'
 * @param {any} messagePayload - Payload data
 * @return {Promise<{ messageId?: number, targetUrl: string }>}
 */
export async function postPayloadToRoom(token, messageType, messagePayload) {
	if (messageType === 'json-ld') {
		if (!isJsonLdSupported()) {
			console.warn('[TalkShare] JSON-LD sharing is not supported on this Talk installation')
			throw new Error('JSON-LD sharing is not supported on this server')
		}

		let jsonObj = messagePayload
		if (typeof messagePayload === 'string') {
			jsonObj = JSON.parse(messagePayload)
		}

		// Support both single objects and arrays (e.g. @graph or multi-entity array)
		const root = Array.isArray(jsonObj) ? jsonObj[0] : jsonObj
		const name = root?.name || root?.headline || root?.['@type'] || 'JSON-LD Data'
		const description = root?.description || root?.articleBody || ''
		const jsonld = typeof messagePayload === 'string' ? messagePayload : JSON.stringify(messagePayload)

		// Unique identifier for this RichObject instance on Talk
		const cardId = Date.now().toString(36) + Math.random().toString(36).substring(2, 7)

		const metaData = JSON.stringify({
			id: cardId,
			name,
			description,
			jsonld,
		})

		const response = await postRichObjectToConversation(token, {
			objectType: 'json-ld-card',
			objectId: cardId,
			metaData,
		})
		const messageId = Number(response.data.ocs.data.id)
		const targetUrl = generateUrl('/call/{token}#message_{messageId}', { token, messageId })
		return { messageId, targetUrl }
	}

	if (messageType === 'file') {
		const response = await shareFile({
			path: messagePayload,
			shareWith: token,
		})
		const messageId = Number(response?.data?.ocs?.data?.id) || undefined
		const targetUrl = generateUrl('/call/{token}', { token })
		return { messageId, targetUrl }
	}

	// Plain text or URL
	const response = await postNewMessage({
		token,
		message: messagePayload,
	})
	const messageId = Number(response.data.ocs.data.id)
	const targetUrl = generateUrl('/call/{token}#message_{messageId}', { token, messageId })
	return { messageId, targetUrl }
}

/**
 * Universal Talk share entry point
 *
 * @param {string|any} messageType - 'text' | 'json-ld' | 'file' (or messagePayload directly if single argument)
 * @param {any} [messagePayload] - Payload to share
 * @param {object} [options] - Options: { redirect?: boolean, roomToken?: string, title?: string }
 * @return {Promise<{ token: string, messageId?: number, targetUrl: string } | void>}
 */
export async function share(messageType, messagePayload, options) {
	// Flexible single-argument normalization: share('some text') or share(jsonLdObject)
	if (messagePayload === undefined && options === undefined) {
		if (typeof messageType === 'object' && messageType !== null) {
			messagePayload = messageType
			messageType = 'json-ld'
		} else {
			messagePayload = messageType
			messageType = 'text'
		}
		options = {}
	} else if (typeof messageType !== 'string' && options === undefined) {
		// e.g. share(jsonLdObject, { redirect: true })
		options = messagePayload || {}
		messagePayload = messageType
		messageType = 'json-ld'
	}

	options = options || {}
	const showToast = options.showToast !== false

	// Validate input contract
	const validation = validateShareInput(messageType, messagePayload)
	if (!validation.valid) {
		console.warn(`[TalkShare] Input validation failed: ${validation.error}`)
		if (showToast) {
			showError(validation.error)
		}
		return { success: false, error: validation.error }
	}

	const redirect = Boolean(options.redirect)
	const roomToken = options.roomToken || options.token

	if (redirect) {
		const itemId = Date.now().toString(36) + Math.random().toString(36).substring(2, 7)
		sessionStorage.setItem('talk_sendto_' + itemId, JSON.stringify({ messageType, messagePayload }))
		const target = roomToken
			? generateUrl('/apps/spreed/sendto?itemid={itemid}&token={token}', { token: roomToken, itemid: itemId })
			: generateUrl('/apps/spreed/sendto?itemid={itemid}', { itemid: itemId })
		window.location.href = target
		return { success: true, redirecting: true }
	}

	// In-place share (fire and forget)
	let targetToken = roomToken
	let displayName

	if (!targetToken) {
		const conversation = await requestRoomSelection('talk-share-room-select', {
			dialogTitle: options.title || options.dialogTitle || t('spreed', 'Share to conversation'),
			showPostableOnly: true,
		})
		if (!conversation) {
			// User canceled dialog
			return { success: false, canceled: true }
		}
		targetToken = conversation.token
		displayName = conversation.displayName
	}

	try {
		const { messageId, targetUrl } = await postPayloadToRoom(targetToken, messageType, messagePayload)
		const roomLabel = displayName ? escapeHtml(displayName) : targetToken
		if (showToast) {
			showSuccess(
				t('spreed', 'Shared to {conversation}')
					.replace(/\{conversation}/g, `<a target="_blank" class="external" href="${targetUrl}">${roomLabel} ↗</a>`),
				{ isHTML: true },
			)
		}
		return { success: true, token: targetToken, messageId, targetUrl }
	} catch (error) {
		console.error('[TalkShare] Error sharing to conversation:', error)
		const errorMsg = error?.response?.status === 403
			? t('spreed', 'No permission to post messages in this conversation')
			: (error?.message || t('spreed', 'An error occurred while sharing to conversation'))
		if (showToast) {
			showError(errorMsg)
		}
		return { success: false, error: errorMsg }
	}
}

// CSP config for dynamic chunk loading
__webpack_nonce__ = getCSPNonce()
__webpack_public_path__ = generateFilePath('spreed', '', 'js/')

// Expose globally
if (!window.OCA) {
	window.OCA = {}
}
if (!window.OCA.Talk) {
	window.OCA.Talk = {}
}
window.OCA.Talk.share = share
