<?php
/**
 * The shipped translations.
 *
 * @package WooProductCategorizerAi
 */

namespace WooProductCategorizerAi\Tests;

use WooProductCategorizerAi\Plugin;
use WP_UnitTestCase;

/**
 * German is a requirement here rather than a courtesy: the shop this was built for
 * is Swiss-German, and an English admin screen would be the wrong answer for the
 * only person who ever sees it.
 *
 * The POT being in step with the source is checked by bin/check-translations.sh,
 * which can compare files. This runs inside WordPress and checks the thing that
 * script cannot: that the compiled catalogues actually load and translate.
 */
class I18nTest extends WP_UnitTestCase {

	/**
	 * Clean up after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		unload_textdomain( Plugin::TEXT_DOMAIN );

		parent::tear_down();
	}

	/**
	 * The languages directory.
	 *
	 * @return string
	 */
	protected function dir() {
		return WPCAI_PLUGIN_DIR . 'languages/';
	}

	/**
	 * Load one locale's catalogue.
	 *
	 * @param string $locale Locale to load.
	 * @return bool Whether it loaded.
	 */
	protected function load( $locale ) {
		unload_textdomain( Plugin::TEXT_DOMAIN );

		return load_textdomain(
			Plugin::TEXT_DOMAIN,
			$this->dir() . Plugin::TEXT_DOMAIN . '-' . $locale . '.mo'
		);
	}

	/**
	 * Every catalogue the plugin claims to ship has to be there and compiled.
	 *
	 * @return void
	 */
	public function test_the_shipped_catalogues_exist() {
		$this->assertFileExists( $this->dir() . Plugin::TEXT_DOMAIN . '.pot' );

		foreach ( array( Plugin::GERMAN_INFORMAL, Plugin::GERMAN_FORMAL ) as $locale ) {
			$base = $this->dir() . Plugin::TEXT_DOMAIN . '-' . $locale;

			$this->assertFileExists( $base . '.po', $locale . ' should have a catalogue.' );
			$this->assertFileExists( $base . '.mo', $locale . ' should be compiled.' );
			$this->assertFileExists( $base . '.l10n.php', $locale . ' should have a PHP catalogue.' );
		}
	}

	/**
	 * A catalogue that loads but translates nothing is the failure this catches —
	 * a mismatched text domain looks exactly like a missing translation.
	 *
	 * @return void
	 */
	public function test_the_german_catalogue_actually_translates() {
		$this->assertTrue( $this->load( Plugin::GERMAN_INFORMAL ) );

		$translated = __( 'Category tree', 'woo-product-categorizer-ai' );

		$this->assertNotSame( 'Category tree', $translated );
		$this->assertSame( 'Kategoriebaum', $translated );
	}

	/**
	 * The two registers are what make the formal catalogue worth shipping at all.
	 *
	 * @return void
	 */
	public function test_the_formal_catalogue_addresses_the_reader_formally() {
		$this->load( Plugin::GERMAN_FORMAL );
		$formal = __( 'You do not have permission to do that.', 'woo-product-categorizer-ai' );

		$this->load( Plugin::GERMAN_INFORMAL );
		$informal = __( 'You do not have permission to do that.', 'woo-product-categorizer-ai' );

		$this->assertStringContainsString( 'Sie ', $formal );
		$this->assertStringContainsString( 'Du ', $informal );
		$this->assertNotSame( $formal, $informal );
	}

	/**
	 * A placeholder lost in translation is a PHP warning and a broken sentence in
	 * front of whoever is running the job.
	 *
	 * @return void
	 */
	public function test_every_translation_keeps_its_placeholders() {
		foreach ( array( Plugin::GERMAN_INFORMAL, Plugin::GERMAN_FORMAL ) as $locale ) {
			$entries = $this->read_po( $this->dir() . Plugin::TEXT_DOMAIN . '-' . $locale . '.po' );

			foreach ( $entries as $source => $translation ) {
				if ( '' === $translation ) {
					continue;
				}

				$this->assertSame(
					$this->placeholders( $source ),
					$this->placeholders( $translation ),
					sprintf( '%s: placeholders differ for "%s"', $locale, $source )
				);
			}
		}
	}

	/**
	 * Only proper nouns are allowed to go untranslated.
	 *
	 * @return void
	 */
	public function test_nothing_but_proper_nouns_is_left_untranslated() {
		$allowed = array(
			'',
			'Woo Product Categorizer AI',
			'https://github.com/SourovCodes/woo-product-categorizer-ai',
			'Sourov Biswas',
			'OpenAI',
		);

		foreach ( array( Plugin::GERMAN_INFORMAL, Plugin::GERMAN_FORMAL ) as $locale ) {
			$entries = $this->read_po( $this->dir() . Plugin::TEXT_DOMAIN . '-' . $locale . '.po' );

			foreach ( $entries as $source => $translation ) {
				if ( '' === $translation ) {
					$this->assertContains( $source, $allowed, sprintf( '%s: "%s" is untranslated', $locale, $source ) );
				}
			}
		}
	}

	/**
	 * The placeholders a string uses, sorted so order does not matter.
	 *
	 * @param string $text A translatable string.
	 * @return array
	 */
	protected function placeholders( $text ) {
		preg_match_all( '/%(?:\d+\$)?[sd]|%%/', $text, $matches );

		$found = $matches[0];

		sort( $found );

		return $found;
	}

	/**
	 * Read a PO file into source => translation pairs.
	 *
	 * A small reader rather than a dependency: the suite has to run on a runner
	 * with nothing but PHP, and the format needed here is two fields.
	 *
	 * @param string $path Path to a .po file.
	 * @return array
	 */
	protected function read_po( $path ) {
		$entries = array();

		/*
		 * file_get_contents() rather than wp_remote_get(): this is a file on disk in
		 * the plugin being tested, not a remote URL, and the sniff cannot tell the
		 * difference from the call alone.
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local catalogue, not a URL.
		$blocks = preg_split( '/\n\s*\n/', (string) file_get_contents( $path ) );

		foreach ( $blocks as $block ) {
			$source = $this->field( $block, 'msgid' );

			if ( null === $source ) {
				continue;
			}

			/*
			 * A plural entry has no plain "msgstr" — it has msgstr[0] and msgstr[1],
			 * one per form. Reading only "msgstr" reports both halves of every _n()
			 * call as untranslated, which is how this reader was wrong the first time.
			 */
			$plural = $this->field( $block, 'msgid_plural' );

			if ( null !== $plural ) {
				$entries[ $source ] = (string) $this->field( $block, 'msgstr\[0\]' );
				$entries[ $plural ] = (string) $this->field( $block, 'msgstr\[1\]' );
				continue;
			}

			$translation = $this->field( $block, 'msgstr' );

			$entries[ $source ] = null === $translation ? '' : $translation;
		}

		return $entries;
	}

	/**
	 * Read one field out of a PO block, joining its continuation lines.
	 *
	 * @param string $block One PO entry.
	 * @param string $field Field name as a regular expression fragment.
	 * @return string|null
	 */
	protected function field( $block, $field ) {
		if ( ! preg_match( '/^' . $field . ' (.*)$((?:\n^".*"$)*)/m', $block, $matches ) ) {
			return null;
		}

		$raw = $matches[1] . ( isset( $matches[2] ) ? $matches[2] : '' );

		preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $raw, $parts );

		return implode( '', $parts[1] );
	}
}
