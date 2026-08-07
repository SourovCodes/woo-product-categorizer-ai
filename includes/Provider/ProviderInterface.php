<?php
/**
 * The contract every LLM backend satisfies.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * The seam between the plugin and whichever model is answering.
 *
 * Everything above this interface — the proposal job, the assignment job, the
 * settings screen — speaks in instructions, input, schema and effort. No vendor's
 * field names, response shapes or endpoint paths appear outside the class that
 * implements this.
 */
interface ProviderInterface {

	/**
	 * The stable identifier this provider is stored under.
	 *
	 * Used as the key into the settings' api_keys and models maps, so changing it
	 * would strand an existing configuration.
	 *
	 * @return string
	 */
	public static function id();

	/**
	 * The provider's name, as shown in the settings dropdown.
	 *
	 * @return string
	 */
	public static function label();

	/**
	 * The model to use when none has been chosen.
	 *
	 * @return string
	 */
	public static function recommended_model();

	/**
	 * The handful of models worth putting in front of someone choosing.
	 *
	 * Shown as a "Recommended" group above everything else the account can reach.
	 *
	 * @return array Model id => label.
	 */
	public static function curated_models();

	/**
	 * List the models this account can actually use.
	 *
	 * @return array|\WP_Error Model ids, or an error.
	 */
	public function list_models();

	/**
	 * Check that the stored credentials work.
	 *
	 * @return true|\WP_Error True when the provider answered, or an error.
	 */
	public function test_connection();

	/**
	 * Ask for one structured answer.
	 *
	 * The request is provider-neutral:
	 *
	 * - `instructions` — the part that does not vary across a run. Providers that
	 *   cache prompts cache this, so callers must render it once and reuse the exact
	 *   same string.
	 * - `input`        — the part that does vary. The batch of products, the sample.
	 * - `schema`       — a strict JSON Schema with an object at its root.
	 * - `schema_name`  — a name for that schema.
	 * - `effort`       — 'low' or 'medium'. How much thinking the answer is worth.
	 * - `max_tokens`   — a ceiling on the answer.
	 * - `cache_key`    — a hint letting the provider route repeat requests together.
	 *
	 * The result is provider-neutral too: `payload` is the decoded JSON, already
	 * matching the schema, and `usage` carries input_tokens, output_tokens,
	 * reasoning_tokens and cached_tokens.
	 *
	 * @param array $request The request described above.
	 * @return array|\WP_Error Payload and usage, or an error carrying a disposition.
	 */
	public function complete( array $request );
}
