<?php
/**
 * AI layer: one interface, three providers (OpenAI, Anthropic Claude, Google Gemini).
 * Only aggregated statistics are sent – never IP addresses or raw visitor rows.
 *
 * The free plugin ships one report type ("quick" AI summary). The Pro add-on registers
 * the in-depth reports and the chat through the "ntaa_ai_types" / "ntaa_ai_chat" filters.
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * AI.
 */
class NTAA_AI {

	/* ---------------------------------------------------------------
	 * Report types
	 * ------------------------------------------------------------- */

	/**
	 * Available report types: id => label, icon, desc, task (the instruction sent to the AI).
	 *
	 * @return array
	 */
	public static function analysis_types() {
		$types = array(
			'quick' => array(
				'label' => __( 'AI summary', 'nodethemes-analytics-ai' ),
				'icon'  => 'lightbulb',
				'desc'  => __( 'Five key insights and the one action to take this week', 'nodethemes-analytics-ai' ),
				'task'  => 'Give a very short smart summary: maximum 5 bullet points with the most important insights, each one line, and finish with one single most important action to take this week. No headings, no tables.',
			),
		);
		$types = apply_filters( 'ntaa_ai_types', $types );
		return is_array( $types ) ? $types : array();
	}

	/**
	 * Is the chat with the data available (Pro)?
	 *
	 * @return bool
	 */
	public static function chat_enabled() {
		return (bool) apply_filters( 'ntaa_ai_chat', false );
	}

	/**
	 * Types for the admin UI: available ones plus locked Pro previews.
	 *
	 * @return array
	 */
	public static function ui_types() {
		$out = array();
		foreach ( self::analysis_types() as $id => $t ) {
			$out[ $id ] = array(
				'label'  => isset( $t['label'] ) ? $t['label'] : $id,
				'icon'   => isset( $t['icon'] ) ? $t['icon'] : 'analytics',
				'desc'   => isset( $t['desc'] ) ? $t['desc'] : '',
				'locked' => false,
			);
		}
		foreach ( NTAA_Pro::locked_reports() as $id => $t ) {
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array_merge( $t, array( 'locked' => true ) );
			}
		}
		// Quick summary last, like the original order of the Pro reports.
		if ( isset( $out['quick'] ) ) {
			$q = $out['quick'];
			unset( $out['quick'] );
			$out['quick'] = $q;
		}
		return $out;
	}

	/* ---------------------------------------------------------------
	 * Context built from statistics
	 * ------------------------------------------------------------- */

	/**
	 * Aggregated data sent to the AI.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array
	 */
	public static function context( $from, $to, $site = 1, $segment = array() ) {
		$stats  = new NTAA_Stats( $from, $to, $site, $segment );
		$r      = $stats->report( 'all' );
		$trim   = function ( $rows, $n, $drop = array() ) {
			$out = array();
			foreach ( array_slice( (array) $rows, 0, $n ) as $row ) {
				foreach ( $drop as $k ) {
					unset( $row[ $k ] );
				}
				$out[] = $row;
			}
			return $out;
		};
		$labels = array( 1 => 'Sun', 2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat' );
		$by_day = array();
		$by_hr  = array_fill( 0, 24, 0 );
		foreach ( $r['heatmap'] as $dow => $hours ) {
			$by_day[ $labels[ $dow ] ] = array_sum( $hours );
			foreach ( $hours as $h => $c ) {
				$by_hr[ $h ] += $c;
			}
		}
		$segment_text = array();
		$fields       = NTAA_Segments::fields();
		foreach ( $stats->segment as $c ) {
			$segment_text[] = ( isset( $fields[ $c['f'] ] ) ? $fields[ $c['f'] ][0] : $c['f'] ) . ' ' . $c['o'] . ' ' . implode( ' or ', $c['v'] );
		}
		$ctx = array(
			'site'              => array(
				'name'            => get_bloginfo( 'name' ),
				'description'     => get_bloginfo( 'description' ),
				'url'             => home_url(),
				'language'        => get_bloginfo( 'language' ),
				'published_posts' => (int) wp_count_posts( 'post' )->publish,
				'published_pages' => (int) wp_count_posts( 'page' )->publish,
				'is_woocommerce'  => class_exists( 'WooCommerce' ),
			),
			'notes'             => array(
				'durations are in seconds, load times and Web Vitals (LCP, INP, TTFB, FCP) in milliseconds, CLS is unitless, rates in percent, money in the shop currency',
				'bounce = visit with a single pageview; visitors = sum of daily unique visitors',
				'channel "ai" = visitors sent by AI assistants such as ChatGPT, Perplexity, Gemini or Claude',
				$r['cookieless'] ? 'cookieless mode: visitors are counted per day, new vs returning is not reliable' : 'cookie mode: new vs returning is reliable',
			),
			'segment'           => $segment_text ? 'Only visits matching: ' . implode( ' AND ', $segment_text ) : 'all visits',
			'range'             => $r['range'],
			'previous_range'    => $r['previous_range'],
			'summary'           => $r['summary'],
			'previous_summary'  => $r['previous'],
			'timeseries'        => $r['timeseries'],
			'top_pages'         => $trim( $r['pages'], 30, array( 'vitals_n' ) ),
			'entry_pages'       => $trim( $r['entry_pages'], 15 ),
			'exit_pages'        => $trim( $r['exit_pages'], 15 ),
			'site_search_terms' => $trim( $r['site_search'], 20 ),
			'searches_without_results' => $trim( $r['search_no_results'], 15 ),
			'performance'       => $r['performance'],
			'channels'          => $r['referrer_types'],
			'ai_assistants'     => $trim( $r['ai_assistants'], 10 ),
			'referrers'         => $trim( $r['referrers'], 25 ),
			'campaigns'         => $trim( $r['campaigns'], 15 ),
			'devices'           => $r['devices'],
			'browsers'          => $trim( $r['browsers'], 8 ),
			'operating_systems' => $trim( $r['os'], 8 ),
			'countries'         => $trim( $r['countries'], 15 ),
			'cities'            => $trim( $r['cities'], 15 ),
			'languages'         => $trim( $r['languages'], 10 ),
			'visit_duration_distribution' => $r['duration_b'],
			'pages_per_visit_distribution' => $r['pages_b'],
			'visits_by_weekday' => $by_day,
			'visits_by_hour'    => $by_hr,
			'events'            => $trim( $r['events'], 25 ),
			'top_event_labels'  => $trim( $r['event_labels'], 15 ),
			'goals'             => $r['goals'],
			'goal_conversions_by_channel' => $r['goal_channel'],
		);
		if ( isset( $r['ecommerce'] ) ) {
			$ctx['ecommerce']          = $r['ecommerce'];
			$ctx['ecommerce_previous'] = $r['ecommerce_previous'];
			$ctx['top_products']       = $trim( $r['products'], 20 );
			$ctx['product_categories'] = $trim( $r['categories'], 10 );
			$ctx['revenue_by_channel'] = $trim( $r['revenue_channel'], 10 );
		}
		return apply_filters( 'ntaa_ai_context', $ctx, $stats );
	}

	/**
	 * Language of the reports, in English, for the prompt.
	 *
	 * @return string
	 */
	public static function language_name() {
		$langs = NTAA_Settings::languages();
		$l     = (string) NTAA_Settings::get( 'ai_language', 'site' );
		if ( 'site' === $l || ! isset( $langs[ $l ] ) ) {
			$code = strtolower( substr( determine_locale(), 0, 2 ) );
			return isset( $langs[ $code ] ) && $langs[ $code ] ? $langs[ $code ] : 'English';
		}
		return $langs[ $l ];
	}

	/**
	 * System prompt with the data.
	 *
	 * @param array $context Context.
	 * @return string
	 */
	public static function system_prompt( $context ) {
		$lang = self::language_name();
		$note = trim( (string) NTAA_Settings::get( 'ai_custom_note', '' ) );
		$s    = "You are a senior web-analytics consultant (Matomo / Google Analytics expert, SEO and CRO specialist) embedded in the WordPress plugin NodeThemes Analytics AI.\n"
			. "Rules:\n"
			. "- Always answer in {$lang}. Keep URLs, page paths and technical names as they are.\n"
			. "- Base every claim strictly on the JSON data provided. Quote real numbers. Never invent pages, numbers or sources. If data is insufficient (e.g. very little traffic), say so clearly and explain what to collect.\n"
			. "- Compare with the previous period when useful and show percentage changes.\n"
			. "- Be practical: every insight should lead to an action.\n"
			. "- Format with Markdown: short headings (##), bullet lists, **bold** for key numbers, and tables only when they really help.\n";
		if ( '' !== $note ) {
			$s .= "\nBusiness context from the site owner (use it to tailor advice):\n" . $note . "\n";
		}
		$s .= "\nANALYTICS DATA (JSON):\n" . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return $s;
	}

	/* ---------------------------------------------------------------
	 * Public entry point
	 * ------------------------------------------------------------- */

	/**
	 * Run a report or a chat turn.
	 *
	 * @param string $type     Report type or "chat".
	 * @param string $from     Y-m-d.
	 * @param string $to       Y-m-d.
	 * @param string $question Extra instruction or chat question.
	 * @param array  $history  Chat history.
	 * @param string $provider Provider override.
	 * @return array|WP_Error  ['text','provider','model','id']
	 */
	public static function analyze( $type, $from, $to, $question = '', $history = array(), $provider = '', $site = 1, $segment = array() ) {
		$types             = self::analysis_types();
		list( $from, $to ) = NTAA_Stats::normalize_range( $from, $to );

		if ( 'chat' === $type ) {
			if ( ! self::chat_enabled() ) {
				return new WP_Error( 'ntaa_pro', __( 'The AI chat is a Pro feature. Activate your NodeThemes Analytics AI Pro license to use it.', 'nodethemes-analytics-ai' ), array( 'status' => 403 ) );
			}
		} elseif ( ! isset( $types[ $type ] ) || empty( $types[ $type ]['task'] ) ) {
			return new WP_Error( 'ntaa_pro', __( 'This report is a Pro feature. Activate your NodeThemes Analytics AI Pro license to use it.', 'nodethemes-analytics-ai' ), array( 'status' => 403 ) );
		}

		$system   = self::system_prompt( self::context( $from, $to, $site, $segment ) );
		$messages = array();
		if ( 'chat' === $type ) {
			foreach ( array_slice( (array) $history, -10 ) as $h ) {
				if ( ! is_array( $h ) || ! isset( $h['role'], $h['content'] ) ) {
					continue;
				}
				$messages[] = array(
					'role'    => 'assistant' === $h['role'] ? 'assistant' : 'user',
					'content' => mb_substr( wp_strip_all_tags( (string) $h['content'] ), 0, 8000 ),
				);
			}
			$q = trim( (string) $question );
			if ( '' === $q ) {
				return new WP_Error( 'ntaa_empty', __( 'Please type a question.', 'nodethemes-analytics-ai' ), array( 'status' => 400 ) );
			}
			$messages[] = array(
				'role'    => 'user',
				'content' => mb_substr( $q, 0, 4000 ),
			);
			$prompt     = $q;
		} else {
			$task = $types[ $type ]['task'];
			if ( '' !== trim( (string) $question ) ) {
				$task .= "\n\nAdditional instruction from the user: " . mb_substr( trim( (string) $question ), 0, 2000 );
			}
			$messages[] = array(
				'role'    => 'user',
				'content' => $task,
			);
			$prompt     = $types[ $type ]['label'] . ( $question ? ' — ' . $question : '' );
		}

		$res = self::complete( $system, $messages, $provider );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			NTAA_DB::t( 'reports' ),
			array(
				'created_at' => wp_date( 'Y-m-d H:i:s' ),
				'user_id'    => get_current_user_id(),
				'provider'   => $res['provider'],
				'model'      => $res['model'],
				'date_from'  => $from,
				'date_to'    => $to,
				'type'       => $type,
				'prompt'     => mb_substr( $prompt, 0, 4000 ),
				'response'   => $res['text'],
			)
		);
		$res['id'] = (int) $wpdb->insert_id;
		return $res;
	}

	/* ---------------------------------------------------------------
	 * Provider dispatch
	 * ------------------------------------------------------------- */

	/**
	 * Send a prompt to the chosen provider.
	 *
	 * @param string $system   System prompt.
	 * @param array  $messages Messages.
	 * @param string $provider Provider id.
	 * @return array|WP_Error
	 */
	public static function complete( $system, $messages, $provider = '' ) {
		$providers = NTAA_Settings::providers();
		if ( ! $provider || ! isset( $providers[ $provider ] ) ) {
			$provider = NTAA_Settings::get( 'ai_provider', 'openai' );
		}
		$key   = NTAA_Settings::get_api_key( $provider );
		$model = NTAA_Settings::get( $provider . '_model', $providers[ $provider ]['model'] );
		$base  = trim( (string) NTAA_Settings::get( $provider . '_base', '' ) );
		if ( '' === $key && '' === $base ) {
			/* translators: %s: provider name */
			return new WP_Error( 'ntaa_no_key', sprintf( __( 'No API key is saved for %s. Add it under Analytics AI → Settings → AI.', 'nodethemes-analytics-ai' ), $providers[ $provider ]['label'] ), array( 'status' => 400 ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 240 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$max = (int) NTAA_Settings::get( 'ai_max_tokens', 6000 );

		switch ( $provider ) {
			case 'anthropic':
				$text = self::anthropic( $key, $model, $base, $system, $messages, $max );
				break;
			case 'gemini':
				$text = self::gemini( $key, $model, $base, $system, $messages, $max );
				break;
			default:
				$text = self::openai( $key, $model, $base, $system, $messages, $max );
		}
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		return array(
			'text'     => $text,
			'provider' => $provider,
			'model'    => $model,
		);
	}

	/**
	 * POST JSON and decode.
	 *
	 * @param string $url     URL.
	 * @param array  $headers Headers.
	 * @param array  $body    Body.
	 * @return array|WP_Error
	 */
	private static function request( $url, $headers, $body ) {
		$res = wp_remote_post(
			$url,
			array(
				'timeout' => 180,
				'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
			)
		);
		if ( is_wp_error( $res ) ) {
			/* translators: %s: error */
			return new WP_Error( 'ntaa_http', sprintf( __( 'Could not reach the AI provider: %s', 'nodethemes-analytics-ai' ), $res->get_error_message() ), array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = '';
			if ( is_array( $json ) ) {
				if ( isset( $json['error']['message'] ) ) {
					$msg = $json['error']['message'];
				} elseif ( isset( $json['error'] ) && is_string( $json['error'] ) ) {
					$msg = $json['error'];
				} elseif ( isset( $json['message'] ) ) {
					$msg = $json['message'];
				}
			}
			if ( ! $msg ) {
				$msg = mb_substr( wp_strip_all_tags( wp_remote_retrieve_body( $res ) ), 0, 300 );
			}
			/* translators: 1: HTTP code, 2: message */
			return new WP_Error( 'ntaa_api', sprintf( __( 'AI provider error (HTTP %1$d): %2$s', 'nodethemes-analytics-ai' ), $code, $msg ), array( 'status' => 502 ) );
		}
		return is_array( $json ) ? $json : new WP_Error( 'ntaa_json', __( 'The AI provider sent an invalid response.', 'nodethemes-analytics-ai' ), array( 'status' => 502 ) );
	}

	/**
	 * OpenAI – Chat Completions (also any OpenAI-compatible endpoint).
	 */
	private static function openai( $key, $model, $base, $system, $messages, $max ) {
		$official = '' === $base;
		$base     = $official ? 'https://api.openai.com/v1' : untrailingslashit( $base );
		$url      = preg_match( '#/chat/completions$#', $base ) ? $base : $base . '/chat/completions';
		$body     = array(
			'model'    => $model,
			'messages' => array_merge( array( array( 'role' => 'system', 'content' => $system ) ), $messages ),
		);
		if ( $official ) {
			$body['max_completion_tokens'] = $max;
		} else {
			$body['max_tokens'] = $max;
		}
		$json = self::request( $url, $key ? array( 'Authorization' => 'Bearer ' . $key ) : array(), $body );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$text = isset( $json['choices'][0]['message']['content'] ) ? $json['choices'][0]['message']['content'] : '';
		if ( is_array( $text ) ) {
			$text = implode( "\n", wp_list_pluck( $text, 'text' ) );
		}
		return '' !== trim( (string) $text ) ? (string) $text : new WP_Error( 'ntaa_empty', __( 'Empty answer from OpenAI. Try raising “Max output tokens”.', 'nodethemes-analytics-ai' ), array( 'status' => 502 ) );
	}

	/**
	 * Anthropic – Messages API.
	 */
	private static function anthropic( $key, $model, $base, $system, $messages, $max ) {
		$base  = '' === $base ? 'https://api.anthropic.com' : untrailingslashit( $base );
		$url   = preg_match( '#/v1/messages$#', $base ) ? $base : $base . '/v1/messages';
		$clean = array();
		foreach ( $messages as $m ) {
			// Roles must alternate and start with "user".
			if ( ! $clean && 'assistant' === $m['role'] ) {
				continue;
			}
			$last = count( $clean ) - 1;
			if ( $last >= 0 && $clean[ $last ]['role'] === $m['role'] ) {
				$clean[ $last ]['content'] .= "\n\n" . $m['content'];
			} else {
				$clean[] = $m;
			}
		}
		$json = self::request(
			$url,
			array(
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
			),
			array(
				'model'      => $model,
				'max_tokens' => $max,
				'system'     => $system,
				'messages'   => $clean,
			)
		);
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$text = '';
		foreach ( (array) ( isset( $json['content'] ) ? $json['content'] : array() ) as $block ) {
			if ( isset( $block['type'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}
		return '' !== trim( $text ) ? $text : new WP_Error( 'ntaa_empty', __( 'Empty answer from Claude.', 'nodethemes-analytics-ai' ), array( 'status' => 502 ) );
	}

	/**
	 * Google Gemini – generateContent.
	 */
	private static function gemini( $key, $model, $base, $system, $messages, $max ) {
		$base     = '' === $base ? 'https://generativelanguage.googleapis.com' : untrailingslashit( $base );
		$model    = preg_replace( '#^models/#', '', $model );
		$url      = $base . '/v1beta/models/' . rawurlencode( $model ) . ':generateContent';
		$contents = array();
		foreach ( $messages as $m ) {
			$contents[] = array(
				'role'  => 'assistant' === $m['role'] ? 'model' : 'user',
				'parts' => array( array( 'text' => $m['content'] ) ),
			);
		}
		$json = self::request(
			$url,
			array( 'x-goog-api-key' => $key ),
			array(
				'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
				'contents'          => $contents,
				'generationConfig'  => array( 'maxOutputTokens' => max( $max, 4000 ) ),
			)
		);
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$text = '';
		$parts = isset( $json['candidates'][0]['content']['parts'] ) ? (array) $json['candidates'][0]['content']['parts'] : array();
		foreach ( $parts as $part ) {
			if ( isset( $part['text'] ) && empty( $part['thought'] ) ) {
				$text .= $part['text'];
			}
		}
		if ( '' === trim( $text ) ) {
			$reason = isset( $json['candidates'][0]['finishReason'] ) ? $json['candidates'][0]['finishReason'] : ( isset( $json['promptFeedback']['blockReason'] ) ? $json['promptFeedback']['blockReason'] : '' );
			return new WP_Error( 'ntaa_empty', __( 'Empty answer from Gemini.', 'nodethemes-analytics-ai' ) . ( $reason ? ' (' . $reason . ')' : '' ), array( 'status' => 502 ) );
		}
		return $text;
	}

	/**
	 * Small connection test.
	 *
	 * @param string $provider Provider.
	 * @return array|WP_Error
	 */
	public static function test( $provider ) {
		return self::complete(
			'You are a connection test. Reply with exactly: OK',
			array(
				array(
					'role'    => 'user',
					'content' => 'Say OK',
				),
			),
			$provider
		);
	}
}
