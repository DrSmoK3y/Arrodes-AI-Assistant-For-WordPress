=== Arrodes AI Manager ===
Contributors: drsmok3y, lmdesigners
Donate link: https://lmdesigners.gumroad.com/coffee/
Tags: ai, chatbot, woocommerce, gemini, openrouter, deepseek, ai assistant, customer support, live chat
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Intelligent AI Chatbot and Website Content Manager powered by OpenRouter, Google Gemini, DeepSeek, or Custom OpenAI-compatible API endpoints for WordPress & WooCommerce.

== Description ==

**Arrodes AI Manager** is a 100% free and open-source AI Assistant & Chatbot plugin engineered specifically for WordPress and WooCommerce. 

Unlike commercial SaaS plugins that charge recurring subscription fees per message, cap monthly conversations, or lock essential settings behind expensive tiers, **Arrodes AI Manager connects directly to your own AI API keys** (Google Gemini, OpenRouter, DeepSeek, Moonshot/Kimi, Groq, or any OpenAI-compatible router).

### 🚀 Key Highlights

* **100% Free & Open Source**: No middleman server, no monthly plugin subscription fees, no locked features.
* **WooCommerce Native Store Assistant**: Real-time product search with rich visual product cards featuring live price, stock status, product thumbnail, short description, and direct purchase link.
* **Google Gemini 3.6 Flash & Multi-Model Engine**: Connect to Google Gemini API (gemini-3.6-flash), OpenRouter (100+ models including Llama 3.3, Mistral, Claude), DeepSeek, Kimi, or GroqCloud.
* **Rendered HTML DOM Parsing Indexer**: Bypasses raw regex matching by rendering final post/page HTML via WordPress `the_content` filters. Reliably indexes Elementor widgets, Gutenberg blocks, tabs, galleries, accordions, and headings.
* **Transient-Based Session Storage**: Chat histories live in WordPress transients and automatically expire after a configurable TTL (default 30 mins) — zero database bloat.
* **Resilient JSON Auto-Repair Engine**: Built-in server-side and client-side fallback parsing that automatically detects and heals truncated product recommendation responses so your visitors never see broken raw code.
* **Dual IP & Session Rate Limiting**: Anti-DoS security filters, duplicate message throttling, and customizable maximum token caps.
* **Multiple Display Methods**: Floating launcher with customizable animations (Pulse, Bounce, Wiggle, Static), shortcode `[arrodes_ai_chat]`, and native **Elementor** drag-and-drop widget.
* **Comprehensive UI Customization**: Light, dark, and minimal visual themes, custom primary colors, custom launcher icons/avatars, custom drawer dimensions (width and height), and clickable starter prompt chips.

== Installation ==

### Automatic Installation
1. Log into your WordPress admin dashboard.
2. Go to **Plugins > Add New Plugin > Upload Plugin**.
3. Choose the `arrodes-ai-manager.zip` file and click **Install Now**.
4. Click **Activate Plugin**.

### Manual Installation
1. Download or unzip `arrodes-ai-manager`.
2. Upload the `arrodes-ai-manager` folder to your `/wp-content/plugins/` directory via SFTP or file manager.
3. Activate the plugin through the **Plugins** menu in WordPress.

### Initial Configuration
1. Go to **WordPress Admin > Arrodes AI Manager**.
2. Under the **AI Models & API** tab, select your preferred provider (e.g., Google Gemini API or OpenRouter) and enter your API key.
3. Customize your chatbot branding, greeting message, launcher animation, and visual colors in **Identity & Visuals**.
4. (Optional) Run the **Content Indexer** to build a lightning-fast local search index of your pages and products.
5. Click **Save Changes** and test the chat widget on your frontend.

== Frequently Asked Questions ==

= Where can I get a 100% free API key for small business use? =
You have multiple free options:
1. **Google AI Studio (Gemini 3.6 Flash)**: Go to https://aistudio.google.com/ and create a free API key. Gemini provides a generous free tier with zero subscription cost.
2. **OpenRouter Free Models**: Go to https://openrouter.ai/, create a free API key, and select free models such as `meta-llama/llama-3.3-70b-instruct:free` or `mistralai/mistral-7b-instruct:free`.
3. **GroqCloud**: Go to https://console.groq.com/ and get a free API key with ultra-fast inference for Llama 3 models.

= Does this plugin bloat my WordPress database? =
No. Chat conversations are stored in WordPress transients that automatically purge after the configured inactivity timeout (e.g., 30 minutes). No permanent conversation tables are created.

= Can I use Arrodes AI Manager with Elementor? =
Yes! The plugin includes a dedicated **Arrodes AI Chat** Elementor widget. Simply search for "Arrodes" in the Elementor elements panel and drag it into any section or column.

= What shortcode is available? =
Use `[arrodes_ai_chat]` inside any page, post, or block. You can customize parameters:
`[arrodes_ai_chat width="100%" height="550px" theme="light" primary_color="#14bda9"]`

= How does product recommendation work with WooCommerce? =
When customers ask for product suggestions, the AI retrieves relevant WooCommerce products and responds with an interactive visual card showing product image, price, stock status, and direct link to the product page.

== Screenshots ==

1. screenshot-1.png - Admin settings panel: AI provider selection, model configuration, and API keys.
2. screenshot-2.png - Front-end modern chat widget with WooCommerce product recommendation cards.
3. screenshot-3.png - Identity and visual customization settings (colors, dimensions, launcher animations).
4. screenshot-4.png - Content indexer and security rate limiting configuration.

== Changelog ==

= 1.1.0 =
* Feature: Updated Google Gemini provider to the latest `gemini-3.6-flash` model endpoint.
* Improvement: Integrated dual-layer JSON auto-repair (server-side PHP and client-side JavaScript) to handle truncated product recommendation payloads gracefully.
* Improvement: Optimized default `max_tokens` (1500–2000) for WooCommerce product recommendation queries.
* Improvement: Slimmed down site reader prompts to ensure fast, concise product cards (under 10 words per item, 2–3 items max) preventing token cutoffs.
* Fix: Registered missing `aam_max_tokens` setting in admin options.
* Documentation: Added official WordPress `readme.txt` and markdown documentation.

= 1.0.0 =
* Initial public release.
* Multi-provider AI support (OpenRouter, Google Gemini, DeepSeek, Moonshot/Kimi, Custom OpenAI endpoints).
* WooCommerce product search and live interactive product cards.
* Elementor page builder integration widget.
* Shortcode `[arrodes_ai_chat]` support.
* Transient-based auto-expiring session storage.
* Dual IP and session rate limiting.

== Upgrade Notice ==

= 1.1.0 =
Recommended update: includes Google Gemini 3.6 Flash support, enhanced token limits, and resilient JSON auto-repair for WooCommerce product cards.
