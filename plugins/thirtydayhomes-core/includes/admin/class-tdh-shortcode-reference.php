<?php
/**
 * Shortcode reference screen.
 *
 * @package ThirtyDayHomes
 */

declare( strict_types = 1 );

namespace TDH\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * A copy-and-paste reference for every marketplace shortcode.
 *
 * Shortcodes are the delivery mechanism for all dynamic blocks. Their one
 * real weakness against a visual widget panel is discoverability: nobody
 * remembers that `orderby` accepts `price_asc`, and a typo fails silently.
 *
 * This screen is the answer to that. Every shortcode, every attribute,
 * every accepted value, with a click-to-copy example. It costs an hour and
 * it is the difference between a client who can compose pages and a client
 * who emails us to change a number.
 */
final class Shortcode_Reference {

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
	}

	public function add_page(): void {
		add_submenu_page(
			'edit.php?post_type=tdh_listing',
			__( 'Shortcodes', 'thirtydayhomes' ),
			__( 'Shortcodes', 'thirtydayhomes' ),
			'edit_tdh_listings',
			'tdh-shortcodes',
			[ $this, 'render' ]
		);
	}

	/**
	 * The reference itself.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function shortcodes(): array {
		return [
			[
				'tag'     => 'tdh_property_grid',
				'title'   => __( 'Property grid', 'thirtydayhomes' ),
				'summary' => __( 'A grid of listings. Only approved listings from members with an active plan ever appear — this cannot show a pending or paused home.', 'thirtydayhomes' ),
				'example' => '[tdh_property_grid count="3" columns="3" heading="Homes ready when you are"]',
				'atts'    => [
					[ 'count', '1–24', '3', __( 'How many homes to show.', 'thirtydayhomes' ) ],
					[ 'columns', '2, 3, 4', '3', __( 'Grid columns on desktop. Collapses on smaller screens automatically.', 'thirtydayhomes' ) ],
					[ 'orderby', 'date, price_asc, price_desc, rand', 'date', __( 'Newest first, cheapest first, dearest first, or shuffled.', 'thirtydayhomes' ) ],
					[ 'neighborhood', __( 'a neighborhood slug', 'thirtydayhomes' ), __( 'all', 'thirtydayhomes' ), __( 'Limit to one neighborhood, for example shadyside.', 'thirtydayhomes' ) ],
					[ 'heading', __( 'any text', 'thirtydayhomes' ), '—', __( 'Section heading. Omit for no heading.', 'thirtydayhomes' ) ],
					[ 'eyebrow', __( 'any text', 'thirtydayhomes' ), '—', __( 'Small gold label above the heading.', 'thirtydayhomes' ) ],
					[ 'subheading', __( 'any text', 'thirtydayhomes' ), '—', __( 'Supporting line under the heading.', 'thirtydayhomes' ) ],
					[ 'show_link', 'yes, no', 'no', __( 'Show a "view all homes" link beside the heading.', 'thirtydayhomes' ) ],
					[ 'link_text', __( 'any text', 'thirtydayhomes' ), __( 'View all homes', 'thirtydayhomes' ), __( 'Label for that link.', 'thirtydayhomes' ) ],
				],
			],
			[
				'tag'     => 'tdh_hero_search',
				'title'   => __( 'Hero search', 'thirtydayhomes' ),
				'summary' => __( 'The large banner with the headline and the search form. The form always posts to the listing archive — the field names are fixed so search cannot be broken by an edit.', 'thirtydayhomes' ),
				'example' => '[tdh_hero_search heading="Stay a while." accent="Feel at home."]',
				'atts'    => [
					[ 'heading', __( 'any text', 'thirtydayhomes' ), 'Stay a while.', __( 'First line of the headline.', 'thirtydayhomes' ) ],
					[ 'accent', __( 'any text', 'thirtydayhomes' ), 'Feel at home.', __( 'Second line, shown in gold italic. Leave empty to omit.', 'thirtydayhomes' ) ],
					[ 'eyebrow', __( 'any text', 'thirtydayhomes' ), 'Furnished homes · 30+ day stays', __( 'Small label above the headline.', 'thirtydayhomes' ) ],
					[ 'lead', __( 'any text', 'thirtydayhomes' ), __( 'the default paragraph', 'thirtydayhomes' ), __( 'Paragraph under the headline.', 'thirtydayhomes' ) ],
					[ 'image', __( 'an image URL', 'thirtydayhomes' ), __( 'theme default', 'thirtydayhomes' ), __( 'Background photograph. Also settable in Appearance → Customize → Hero.', 'thirtydayhomes' ) ],
					[ 'button_text', __( 'any text', 'thirtydayhomes' ), 'Search homes', __( 'Label on the search button.', 'thirtydayhomes' ) ],
					[ 'placeholder', __( 'any text', 'thirtydayhomes' ), 'Neighborhood, ZIP, or hospital', __( 'Greyed-out hint in the location field.', 'thirtydayhomes' ) ],
					[ 'require_dates', 'yes, no', 'yes', __( 'Keep the button dimmed until both dates are chosen.', 'thirtydayhomes' ) ],
					[ 'trust', __( 'text separated by |', 'thirtydayhomes' ), __( 'three defaults', 'thirtydayhomes' ), __( 'Ticked items under the form, for example: Fully furnished|Utilities included', 'thirtydayhomes' ) ],
				],
			],
		];
	}

	public function render(): void {
		?>
		<div class="wrap tdh-shortcodes">
			<h1><?php esc_html_e( 'ThirtyDayHomes shortcodes', 'thirtydayhomes' ); ?></h1>

			<p class="description" style="max-width:60em;font-size:14px;">
				<?php esc_html_e( 'Paste any of these into a page, a post, an Elementor Text or Shortcode widget, or a block. Every attribute is optional — a shortcode on its own uses the defaults below.', 'thirtydayhomes' ); ?>
			</p>

			<?php foreach ( $this->shortcodes() as $sc ) : ?>
				<div class="card" style="max-width:none;padding:20px 22px;margin-top:20px;">

					<h2 style="margin-top:0;"><?php echo esc_html( (string) $sc['title'] ); ?></h2>
					<p style="max-width:62em;"><?php echo esc_html( (string) $sc['summary'] ); ?></p>

					<p>
						<code style="display:inline-block;padding:10px 14px;background:#f6f7f7;border:1px solid #dcdcde;font-size:13px;">
							<?php echo esc_html( (string) $sc['example'] ); ?>
						</code>
					</p>

					<table class="widefat striped" style="margin-top:12px;">
						<thead>
							<tr>
								<th style="width:14%"><?php esc_html_e( 'Attribute', 'thirtydayhomes' ); ?></th>
								<th style="width:22%"><?php esc_html_e( 'Accepts', 'thirtydayhomes' ); ?></th>
								<th style="width:16%"><?php esc_html_e( 'Default', 'thirtydayhomes' ); ?></th>
								<th><?php esc_html_e( 'What it does', 'thirtydayhomes' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( (array) $sc['atts'] as $att ) : ?>
								<tr>
									<td><code><?php echo esc_html( (string) $att[0] ); ?></code></td>
									<td><?php echo esc_html( (string) $att[1] ); ?></td>
									<td><?php echo esc_html( (string) $att[2] ); ?></td>
									<td><?php echo esc_html( (string) $att[3] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

				</div>
			<?php endforeach; ?>

			<div class="card" style="max-width:none;padding:20px 22px;margin-top:20px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Static sections', 'thirtydayhomes' ); ?></h2>
				<p style="max-width:62em;">
					<?php esc_html_e( 'Marketing content — the audience cards, calls to action, FAQs — has no shortcode on purpose. It is text and icons, not marketplace data, so it is built with Elementor\'s own widgets. Apply these CSS classes in Elementor\'s Advanced tab to pick up the site styling:', 'thirtydayhomes' ); ?>
				</p>
				<p>
					<code>audience</code> &nbsp;
					<code>audience-grid</code> &nbsp;
					<code>owner-cta</code> &nbsp;
					<code>cta-stats</code> &nbsp;
					<code>section</code> &nbsp;
					<code>section-title</code> &nbsp;
					<code>overline gold</code> &nbsp;
					<code>gold-btn</code> &nbsp;
					<code>text-btn</code>
				</p>
			</div>

		</div>
		<?php
	}
}
