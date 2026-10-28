<?php
/**
 * Lightweight user-agent, referrer and geo helpers (no external libraries).
 *
 * @package NodeThemes_Analytics_AI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Lightweight user-agent, referrer and geo helpers (no external libraries).
 */
class NTAA_UA {

	/**
	 * Is this user agent a bot / tool rather than a person?
	 *
	 * @param string $ua User agent.
	 * @return bool
	 */
	public static function is_bot( $ua ) {
		if ( '' === trim( (string) $ua ) ) {
			return true;
		}
		return (bool) preg_match( '/bot|crawl|spider|slurp|mediapartners|facebookexternalhit|embedly|quora link|whatsapp|telegrambot|preview|headless|phantom|puppeteer|playwright|selenium|lighthouse|pagespeed|gtmetrix|pingdom|uptime|monitor|curl|wget|python-requests|python-urllib|aiohttp|httpx|go-http|java\/|okhttp|axios|node-fetch|undici|httpclient|scrapy|semrush|ahrefs|mj12|dotbot|petalbot|bytespider|gptbot|chatgpt-user|oai-searchbot|claudebot|claude-user|claude-searchbot|anthropic-ai|ccbot|perplexity|amazonbot|meta-external|google-extended|duckassist|mistralai|cohere|diffbot|yandexbot|baiduspider/i', (string) $ua );
	}

	public static function parse( $ua ) {
		$browser = 'Other';
		$os      = 'Other';
		$device  = 'desktop';

		$rules = array(
			'Edge'             => '/Edg(e|A|iOS)?\//',
			'Opera'            => '/OPR\/|Opera/',
			'Samsung Internet' => '/SamsungBrowser/',
			'Yandex'           => '/YaBrowser/',
			'UC Browser'       => '/UCBrowser/',
			'Firefox'          => '/Firefox|FxiOS/',
			'Chrome'           => '/Chrome|CriOS/',
			'Safari'           => '/Safari/',
		);
		foreach ( $rules as $name => $re ) {
			if ( preg_match( $re, $ua ) ) {
				$browser = $name;
				break;
			}
		}

		if ( preg_match( '/Windows/i', $ua ) ) {
			$os = 'Windows';
		} elseif ( preg_match( '/Android/i', $ua ) ) {
			$os = 'Android';
		} elseif ( preg_match( '/iPhone|iPad|iPod/i', $ua ) ) {
			$os = 'iOS';
		} elseif ( preg_match( '/Mac OS X|Macintosh/i', $ua ) ) {
			$os = 'macOS';
		} elseif ( preg_match( '/CrOS/i', $ua ) ) {
			$os = 'ChromeOS';
		} elseif ( preg_match( '/Linux/i', $ua ) ) {
			$os = 'Linux';
		}

		if ( preg_match( '/iPad|Tablet|Tab|Kindle|Silk|PlayBook/i', $ua ) || ( preg_match( '/Android/i', $ua ) && ! preg_match( '/Mobile/i', $ua ) ) ) {
			$device = 'tablet';
		} elseif ( preg_match( '/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|Opera Mini/i', $ua ) ) {
			$device = 'mobile';
		}

		return compact( 'browser', 'os', 'device' );
	}

	/**
	 * AI assistants that send visitors (ChatGPT, Perplexity, Gemini, Claude, Copilot…).
	 *
	 * @param string $host Referrer host or utm_source value.
	 * @return string Display name, or '' when not an AI assistant.
	 */
	public static function ai_referrer( $host ) {
		$host = strtolower( preg_replace( '/^www\./', '', (string) $host ) );
		$map  = array(
			'chatgpt.com'          => 'ChatGPT',
			'chat.openai.com'      => 'ChatGPT',
			'openai.com'           => 'ChatGPT',
			'perplexity.ai'        => 'Perplexity',
			'gemini.google.com'    => 'Gemini',
			'bard.google.com'      => 'Gemini',
			'claude.ai'            => 'Claude',
			'copilot.microsoft.com' => 'Copilot',
			'copilot.cloud.microsoft' => 'Copilot',
			'chat.deepseek.com'    => 'DeepSeek',
			'deepseek.com'         => 'DeepSeek',
			'grok.com'             => 'Grok',
			'x.ai'                 => 'Grok',
			'meta.ai'              => 'Meta AI',
			'chat.mistral.ai'      => 'Mistral Le Chat',
			'you.com'              => 'You.com',
			'phind.com'            => 'Phind',
			'poe.com'              => 'Poe',
			'kagi.com'             => 'Kagi Assistant',
			'chat.qwen.ai'         => 'Qwen',
			'kimi.com'             => 'Kimi',
			'kimi.moonshot.cn'     => 'Kimi',
		);
		foreach ( $map as $d => $name ) {
			if ( $host === $d || substr( $host, -strlen( '.' . $d ) ) === '.' . $d ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * Classify a referrer: direct / ai / search / social / website.
	 *
	 * @param string $host Host.
	 * @return string
	 */
	public static function referrer_type( $host ) {
		if ( '' === $host ) {
			return 'direct';
		}
		if ( '' !== self::ai_referrer( $host ) ) {
			return 'ai';
		}
		$search = '/(^|\.)(google|bing|yahoo|duckduckgo|yandex|baidu|ecosia|qwant|naver|seznam|brave|startpage|ask|aol|sogou|so\.com$|daum|mojeek|swisscows|presearch)\./i';
		$social = '/(^|\.)(facebook|fb|instagram|t\.co$|twitter|x\.com$|linkedin|lnkd|t\.me$|telegram|whatsapp|wa\.me$|youtube|youtu\.be$|pinterest|reddit|tiktok|aparat|eitaa|rubika|bale|virgool|threads|snapchat|vk\.com$|discord|quora|medium|tumblr|mastodon|bsky\.app$|weibo|line\.me$)/i';
		if ( preg_match( $search, $host ) ) {
			return 'search';
		}
		if ( preg_match( $social, $host ) ) {
			return 'social';
		}
		return 'website';
	}

	/**
	 * Identify crawlers and AI agents by user agent (for the "Crawlers & AI agents" report).
	 *
	 * @param string $ua User agent.
	 * @return array|null [name, kind] kind: ai | search | seo | social | monitor | other.
	 */
	public static function crawler( $ua ) {
		$list = array(
			'GPTBot'               => array( 'GPTBot', 'ai' ),
			'OAI-SearchBot'        => array( 'OAI-SearchBot (ChatGPT search)', 'ai' ),
			'ChatGPT-User'         => array( 'ChatGPT-User', 'ai' ),
			'ClaudeBot'            => array( 'ClaudeBot', 'ai' ),
			'Claude-User'          => array( 'Claude-User', 'ai' ),
			'Claude-SearchBot'     => array( 'Claude-SearchBot', 'ai' ),
			'anthropic-ai'         => array( 'Anthropic', 'ai' ),
			'PerplexityBot'        => array( 'PerplexityBot', 'ai' ),
			'Perplexity-User'      => array( 'Perplexity-User', 'ai' ),
			'Google-Extended'      => array( 'Google-Extended', 'ai' ),
			'Google-CloudVertexBot' => array( 'Google Vertex AI', 'ai' ),
			'Gemini-Deep-Research' => array( 'Gemini Deep Research', 'ai' ),
			'Applebot-Extended'    => array( 'Applebot-Extended', 'ai' ),
			'meta-externalagent'   => array( 'Meta AI', 'ai' ),
			'meta-externalfetcher' => array( 'Meta AI fetcher', 'ai' ),
			'Bytespider'           => array( 'Bytespider (ByteDance)', 'ai' ),
			'CCBot'                => array( 'CCBot (Common Crawl)', 'ai' ),
			'Amazonbot'            => array( 'Amazonbot', 'ai' ),
			'DuckAssistBot'        => array( 'DuckAssistBot', 'ai' ),
			'MistralAI-User'       => array( 'MistralAI-User', 'ai' ),
			'cohere-ai'            => array( 'Cohere', 'ai' ),
			'YouBot'               => array( 'YouBot', 'ai' ),
			'Diffbot'              => array( 'Diffbot', 'ai' ),
			'Googlebot'            => array( 'Googlebot', 'search' ),
			'bingbot'              => array( 'Bingbot', 'search' ),
			'Applebot'             => array( 'Applebot', 'search' ),
			'YandexBot'            => array( 'YandexBot', 'search' ),
			'Baiduspider'          => array( 'Baiduspider', 'search' ),
			'DuckDuckBot'          => array( 'DuckDuckBot', 'search' ),
			'Slurp'                => array( 'Yahoo Slurp', 'search' ),
			'SeznamBot'            => array( 'SeznamBot', 'search' ),
			'PetalBot'             => array( 'PetalBot', 'search' ),
			'AhrefsBot'            => array( 'AhrefsBot', 'seo' ),
			'SemrushBot'           => array( 'SemrushBot', 'seo' ),
			'MJ12bot'              => array( 'MJ12bot (Majestic)', 'seo' ),
			'DotBot'               => array( 'DotBot (Moz)', 'seo' ),
			'Screaming Frog'       => array( 'Screaming Frog', 'seo' ),
			'facebookexternalhit'  => array( 'Facebook', 'social' ),
			'Twitterbot'           => array( 'X / Twitter', 'social' ),
			'LinkedInBot'          => array( 'LinkedIn', 'social' ),
			'TelegramBot'          => array( 'Telegram', 'social' ),
			'WhatsApp'             => array( 'WhatsApp', 'social' ),
			'Discordbot'           => array( 'Discord', 'social' ),
			'Slackbot'             => array( 'Slack', 'social' ),
			'UptimeRobot'          => array( 'UptimeRobot', 'monitor' ),
			'Pingdom'              => array( 'Pingdom', 'monitor' ),
			'Lighthouse'           => array( 'Lighthouse / PageSpeed', 'monitor' ),
		);
		foreach ( $list as $needle => $info ) {
			if ( false !== stripos( (string) $ua, $needle ) ) {
				return $info;
			}
		}
		if ( preg_match( '/bot|crawl|spider/i', (string) $ua ) ) {
			return array( 'Other bots', 'other' );
		}
		return null;
	}

	/**
	 * Country: server/CDN headers first, then a time-zone guess.
	 */
	/**
	 * Country from CDN headers, then a time-zone guess (used when no GeoIP database is installed).
	 *
	 * @param string $tz Visitor time zone.
	 * @return string
	 */
	public static function country( $tz ) {
		foreach ( array( 'HTTP_CF_IPCOUNTRY', 'HTTP_CLOUDFRONT_VIEWER_COUNTRY', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY', 'GEOIP_COUNTRY_CODE', 'HTTP_X_APPENGINE_COUNTRY', 'HTTP_ARVAN_COUNTRY' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$c = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $_SERVER[ $h ] ), 0, 2 ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				if ( preg_match( '/^[A-Z]{2}$/', $c ) && 'XX' !== $c && 'T1' !== $c ) {
					return $c;
				}
			}
		}
		$map = self::tz_map();
		return isset( $map[ $tz ] ) ? $map[ $tz ] : '';
	}

	public static function tz_map() {
		return array(
			'Asia/Tehran' => 'IR', 'Asia/Dubai' => 'AE', 'Asia/Riyadh' => 'SA', 'Asia/Baghdad' => 'IQ', 'Asia/Kabul' => 'AF',
			'Asia/Karachi' => 'PK', 'Asia/Kolkata' => 'IN', 'Asia/Calcutta' => 'IN', 'Asia/Istanbul' => 'TR', 'Europe/Istanbul' => 'TR',
			'Asia/Baku' => 'AZ', 'Asia/Yerevan' => 'AM', 'Asia/Tbilisi' => 'GE', 'Asia/Qatar' => 'QA', 'Asia/Kuwait' => 'KW',
			'Asia/Bahrain' => 'BH', 'Asia/Muscat' => 'OM', 'Asia/Amman' => 'JO', 'Asia/Beirut' => 'LB', 'Asia/Damascus' => 'SY',
			'Asia/Jerusalem' => 'IL', 'Asia/Dushanbe' => 'TJ', 'Asia/Tashkent' => 'UZ', 'Asia/Ashgabat' => 'TM', 'Asia/Almaty' => 'KZ',
			'Asia/Shanghai' => 'CN', 'Asia/Hong_Kong' => 'HK', 'Asia/Tokyo' => 'JP', 'Asia/Seoul' => 'KR', 'Asia/Singapore' => 'SG',
			'Asia/Bangkok' => 'TH', 'Asia/Jakarta' => 'ID', 'Asia/Manila' => 'PH', 'Asia/Kuala_Lumpur' => 'MY', 'Asia/Dhaka' => 'BD',
			'Asia/Ho_Chi_Minh' => 'VN', 'Asia/Taipei' => 'TW', 'Europe/London' => 'GB', 'Europe/Dublin' => 'IE', 'Europe/Paris' => 'FR',
			'Europe/Berlin' => 'DE', 'Europe/Madrid' => 'ES', 'Europe/Rome' => 'IT', 'Europe/Amsterdam' => 'NL', 'Europe/Brussels' => 'BE',
			'Europe/Zurich' => 'CH', 'Europe/Vienna' => 'AT', 'Europe/Stockholm' => 'SE', 'Europe/Oslo' => 'NO', 'Europe/Copenhagen' => 'DK',
			'Europe/Helsinki' => 'FI', 'Europe/Warsaw' => 'PL', 'Europe/Prague' => 'CZ', 'Europe/Budapest' => 'HU', 'Europe/Bucharest' => 'RO',
			'Europe/Athens' => 'GR', 'Europe/Lisbon' => 'PT', 'Europe/Kiev' => 'UA', 'Europe/Kyiv' => 'UA', 'Europe/Moscow' => 'RU',
			'Europe/Minsk' => 'BY', 'Europe/Sofia' => 'BG', 'Europe/Belgrade' => 'RS', 'Africa/Cairo' => 'EG', 'Africa/Johannesburg' => 'ZA',
			'Africa/Lagos' => 'NG', 'Africa/Nairobi' => 'KE', 'Africa/Casablanca' => 'MA', 'Africa/Algiers' => 'DZ', 'Africa/Tunis' => 'TN',
			'America/New_York' => 'US', 'America/Chicago' => 'US', 'America/Denver' => 'US', 'America/Los_Angeles' => 'US', 'America/Phoenix' => 'US',
			'America/Anchorage' => 'US', 'Pacific/Honolulu' => 'US', 'America/Toronto' => 'CA', 'America/Vancouver' => 'CA', 'America/Edmonton' => 'CA',
			'America/Mexico_City' => 'MX', 'America/Sao_Paulo' => 'BR', 'America/Argentina/Buenos_Aires' => 'AR', 'America/Bogota' => 'CO',
			'America/Lima' => 'PE', 'America/Santiago' => 'CL', 'Australia/Sydney' => 'AU', 'Australia/Melbourne' => 'AU', 'Australia/Perth' => 'AU',
			'Australia/Brisbane' => 'AU', 'Pacific/Auckland' => 'NZ',
		);
	}
}
