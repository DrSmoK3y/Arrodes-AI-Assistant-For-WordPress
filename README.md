# 🤖 Arrodes AI Manager – Industrial WordPress & WooCommerce AI Assistant

[![Version](https://img.shields.io/badge/Version-1.3.0-teal.svg?style=for-the-badge)](https://github.com/DrSmoK3y/Arrodes-AI-Assistant-For-WordPress)
[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg?style=for-the-badge)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-7.0%2B-96588a.svg?style=for-the-badge)](https://woocommerce.com/)
[![PHP Version](https://img.shields.io/badge/PHP-7.4%20|%208.0%20|%208.1%20|%208.2%20|%208.3-4f5b93.svg?style=for-the-badge)](https://php.net)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-emerald.svg?style=for-the-badge)](https://www.gnu.org/licenses/gpl-2.0.html)

> **Developed by:** [LM Designers](https://lmwebdesigners.com/) & [DrSmoK3y](https://www.linkedin.com/in/drsmok3y/)  
> **Donate & Support:** [☕ Buy Us a Coffee](https://lmdesigners.gumroad.com/coffee/)  
> **100% Free & Open Source:** Zero SaaS subscriptions, no third-party data lock-in, no message fees. Connect directly to your own AI API keys.

---

## 🚀 What's New in v1.3.0 (Major Release)

Version 1.3.0 delivers an industrial-grade indexing, search, and customer conversion architecture built specifically to solve real-world e-commerce edge cases, prevent hallucinations, and maximize store revenue:

- 🧱 **Rendered Elementor & Page Builder DOM Parser**:
  - Prioritizes fully rendered HTML via `Elementor\Plugin::$instance->frontend->get_builder_content_for_display()` and `apply_filters('the_content')` over raw database JSON.
  - Guarantees dynamic widgets, shortcodes, ACF fields, popups, and tab content are fully expanded before chunking.
  - Converts HTML tables into self-contained row-wise natural language sentences (`Column Header: Value | Column Header: Value`), ensuring chunks are 100% understandable in isolation.
- ⚡ **Industrial Vector Storage & Binary Packing**:
  - Integrated binary packing helpers (`pack('f*')` / `unpack('f*')`) for fast, memory-efficient float vector manipulation in PHP.
  - Multi-provider vector embeddings: **Google Gemini** (`text-embedding-004` - 768 dim), **OpenAI** (`text-embedding-3-small` - 1536 dim), Custom OpenAI-compatible endpoints, and a zero-cost **Deterministic Basic/Offline Mode** for Groq, DeepSeek, and Kimi.
  - Candidate pre-filtering using MySQL FULLTEXT/BM25 before vector scoring for blazing fast execution even with 20k+ chunks.
- 🛡️ **Edge-Case Indexing & Real-Time Sync**:
  - Automatically skips password-protected posts (`post_password`) to prevent sensitive data leaks.
  - Automatically filters out WooCommerce catalog-hidden products (`get_catalog_visibility() === 'hidden'`).
  - Enriches WooCommerce variable products with variation SKUs, child IDs, and variation pricing.
  - Hooked to `wp_trash_post` and `untrash_post` to instantly remove deleted items from search chunks and restore them upon recovery.
- 🔍 **Hybrid Search & Conversational Roman Urdu Normalizer**:
  - Multi-table retrieval combining semantic chunk vectors, structured catalog index, and direct fallback lookups.
  - Reciprocal Rank Fusion (RRF) and Title Prominence boosting (+0.35) so exact product and page titles never get buried.
  - Built-in phonetic Roman Urdu dictionary and query expansion (e.g., *“rate kya hai”*, *“kitnay ka”*, *“wapis/refund policy”*) that normalizes casual colloquial queries to clean canonical search terms.
  - Contextual follow-up query rewriting (e.g., resolving *“aur is ka price?”* against chat history).
- 💰 **Confidence Threshold & Cost Guard**:
  - Factual queries (product specs, pricing, store policy) with top chunk similarity scores below the confidence threshold bypass external LLM API calls entirely.
  - Protects against expensive token consumption and completely eliminates AI hallucinations.
  - Automatically logs the knowledge gap into the admin dashboard and delivers a verified fallback message with direct WhatsApp support handoff.
- 🛍️ **Live WooCommerce Price & Stock Verification**:
  - Search chunks find the right product, but responses are verified in real time against live WooCommerce database state (`wc_get_product()`). Expired flash sales or out-of-stock items will never be miscommunicated.
  - Server-validated direct Add-to-Cart links (`?add-to-cart=ID`).
- 💬 **WhatsApp Direct Order & Support Handoff**:
  - One-click WhatsApp conversion button (`https://wa.me/{number}?text=...`) pre-filling the exact product title, live price, or support topic so customers can checkout or get human support in seconds.
- 📊 **Admin Knowledge Gaps & Customer Feedback**:
  - Dedicated WordPress admin tab: **Unanswered Gaps & Feedback**.
  - Real-time stat counters: Total Knowledge Gaps Logged, Thumbs Up, Thumbs Down, and Customer Satisfaction Rate (%).
  - Detailed log table of customer queries that fell below the confidence threshold, complete with timestamps, query text, reasons, and masked IPs.
  - One-click AJAX log cleaner (`#aam_clear_unanswered_btn`).
- ✍️ **Typewriter Streaming Mode & Interactive Feedback**:
  - Perceived response latency slashed with word-by-word typewriter streaming animation (`aam_streaming_mode`).
  - Interactive 👍 / 👎 feedback buttons on bot responses with instant AJAX persistence (`aam_save_feedback`).

---

## 📖 Overview

**Arrodes AI Manager** is an intelligent, high-performance, open-source AI Chatbot and Website Content Assistant built specifically for **WordPress** and **WooCommerce**.

Unlike commercial SaaS plugins that charge recurring monthly fees per message or lock essential features behind paywalls, Arrodes AI Manager connects directly to modern AI providers using your own API keys. You maintain 100% control over your data, AI models, styling, and rate limits.

---

## ✨ Comprehensive Feature Matrix

| Feature | Capability |
| :--- | :--- |
| **Multi-Provider AI Engine** | Direct connectivity to **Google Gemini** (`gemini-3.6-flash`), **OpenRouter** (Claude 3.5, Llama 3.3, Mistral, Qwen), **DeepSeek** (`deepseek-chat`), **Moonshot / Kimi**, or any **Custom OpenAI-Compatible Router** (Groq, Together AI, Ollama, vLLM). |
| **Hybrid Search & Embeddings** | Cosine vector similarity + MySQL FULLTEXT keyword matching + Reciprocal Rank Fusion (RRF) + Title Prominence boosting. |
| **Vector Engine Support** | Google Gemini (`text-embedding-004` - 768 dim), OpenAI (`text-embedding-3-small` - 1536 dim), Custom Endpoint, or Basic/Offline Keyword-Hash Mode (free, zero external API cost). |
| **Semantic Chunking** | Intelligent section chunker splitting content by H1-H6 headers, converting HTML tables into row-wise sentences, and extracting FAQs and pricing blocks. |
| **Elementor Deep Integration** | Rendered HTML DOM parsing first to expand shortcodes, popups, and dynamic widgets; JSON fallback if rendered HTML is empty. Drag-and-drop Elementor chat widget included. |
| **Roman Urdu Normalizer** | Rule-based phonetic expansion translating queries like *"rate kya hai"*, *"paisa wapas"*, *"suit dikhao"* into canonical search parameters. |
| **Live WooCommerce Verification** | Fetches live price and stock status via `wc_get_product()`. Generates verified server-side Add-to-Cart links and WhatsApp order links. |
| **Confidence Threshold Guard** | Bypasses LLM API calls on low-confidence queries, saving token costs and preventing hallucinations while logging knowledge gaps. |
| **WhatsApp Direct Conversion** | Pre-fills customer WhatsApp messages with product name, price, and inquiry for frictionless sales in mobile-first markets. |
| **Admin Analytics Panel** | Tracks unanswered customer questions, satisfaction rate (%), thumbs up/down votes, and provides one-click log clearing. |
| **Typewriter Streaming Mode** | Animated word-by-word streaming response with a blinking terminal cursor for fast perceived speed. |
| **Zero Database Bloat** | Conversation histories stored in WordPress transients with auto-expiration (configurable TTL), keeping database tables lean. |
| **Enterprise Security** | Dual IP and session rate limiting, duplicate message throttling, nonce verification on all endpoints, and XSS sanitization. |
| **Visual Customization** | Light and Dark visual modes, custom HEX color picker with two-way sync, custom logo upload, custom widget dimensions, and attention-grabbing launcher animations (*Pulse*, *Bounce*, *Wiggle*, *Static*). |
| **Display Formats** | Floating offcanvas widget, WordPress Shortcode `[arrodes_ai_chat]`, and Elementor widget. |

---

## 🚀 Installation & Quick Start

1. **Upload Plugin**: Upload the `arrodes-ai-manager` folder to `/wp-content/plugins/`, or upload `arrodes-ai-manager.zip` via **WordPress Admin > Plugins > Add New > Upload Plugin**.
2. **Activate**: Activate the plugin through the **Plugins** screen.
3. **Configure API**:
   - Go to **Arrodes AI Manager** in your WordPress admin menu.
   - Under **AI Models & API**, select your provider (e.g., Google Gemini or OpenRouter) and enter your API Key.
4. **Build Search Index**:
   - Go to the **Search & Indexing** tab and click **Reindex & Vectorize All Content Now**.
5. **Configure WhatsApp & Support**:
   - In **Chat Identity & Content**, enter your WhatsApp number (e.g. `923001234567`) and support email.
6. **Save**: Click **Save All Configuration Settings**. Your chatbot is immediately live!

---

## 🔑 Operating at 100% Free Cost (For Small Businesses)

You can operate Arrodes AI Manager with zero software subscription costs:

1. **Google AI Studio (Gemini 3.6 Flash & text-embedding-004)**:
   - Visit [Google AI Studio](https://aistudio.google.com/)
   - Generate your free API key.
   - Provider: **Google Gemini API** (`gemini-3.6-flash`).
   - Embedding: **Google Gemini** (`text-embedding-004`).
2. **OpenRouter Free Tier**:
   - Visit [OpenRouter](https://openrouter.ai/)
   - Select free models such as `meta-llama/llama-3.3-70b-instruct:free`.
3. **GroqCloud Blazing Fast Inference**:
   - Visit [Groq Console](https://console.groq.com/)
   - Set Provider to **Custom Agent Router** with endpoint `https://api.groq.com/openai/v1` and model `openai/gpt-oss-120b`.
   - Set Embedding Provider to **Basic / Offline Mode** (free, zero API calls).

---

## 💻 Shortcode & Elementor Usage

### Shortcode
```text
[arrodes_ai_chat width="100%" height="550px" theme="light" primary_color="#14bda9"]
```

### Elementor Widget
Search for **Arrodes AI Chat** in the Elementor elements panel and drag it into any container, column, or page template.

---

## 📂 Architecture & File Structure

```
arrodes-ai-manager/
├── arrodes-ai-manager.php             # Core bootstrap & plugin singleton
├── readme.txt                         # WordPress.org standard repository readme
├── README.md                          # GitHub repository documentation
├── uninstall.php                      # Database table & transient uninstaller
├── assets/
│   ├── admin.css                      # Admin tabbed dashboard styles & stat cards
│   ├── admin.js                       # AJAX reindexing, search test & log cleaner
│   ├── chat.css                       # Responsive chatbox, product cards & animations
│   └── chat.js                        # Client chat engine, typewriter streaming & feedback
└── includes/
    ├── class_activator.php            # Schema builder (chunks table & catalog index)
    ├── class_admin.php                # Admin UI, settings registration & analytics tab
    ├── class_ai.php                   # Multi-AI provider client & JSON repair
    ├── class_chat_ui.php              # Chat widget renderer, confidence guard & feedback
    ├── class_chunker.php              # DOM rendered HTML chunker & table normalizer
    ├── class_deactivator.php          # Plugin deactivation cleanup
    ├── class_elementor_widget.php     # Native Elementor drag-and-drop widget
    ├── class_embedding_adapter.php    # Multi-provider vector generator & binary packing
    ├── class_hybrid_search.php        # Vector cosine + BM25 keyword + RRF fusion
    ├── class_indexer.php              # Post/Product lifecycle indexer & trash hooks
    ├── class_query_preprocessor.php   # Roman Urdu phonetic normalizer & query rewrite
    ├── class_search.php               # Legacy fulltext fallback search engine
    └── class_site_reader.php          # Fallback site context reader & prompt generator
```

---

## 📝 Release History

### v1.3.0 (Major Architecture & Conversion Release)
- **Rendered Elementor DOM Parser**: HTML parsed via builder/the_content first; table rows converted to natural language sentences (`Header: Val`).
- **Edge-Case Indexing Guards**: Skipped password-protected posts and hidden catalog products; enriched variable product variation SKUs and prices; hooked `wp_trash_post` and `untrash_post`.
- **Vector Storage Optimization**: Binary packing helpers (`pack('f*')`), BM25 candidate pre-filtering, and explicit "Basic / Offline Mode" labeling.
- **Real-Time WooCommerce Verification**: Live price and stock verification via `wc_get_product()`, verified server-side Add-to-Cart links, and automated WhatsApp order pre-fill links.
- **Confidence Threshold & Cost Guard**: Factual queries with low relevance bypass LLM API calls, eliminating hallucinations and logging knowledge gaps.
- **Admin Analytics Dashboard**: Added "Unanswered Gaps & Feedback" tab tracking customer knowledge gaps, satisfaction rate (%), and clear log action.
- **Typewriter Streaming & Feedback**: Word-by-word streaming response and interactive 👍 / 👎 buttons with AJAX persistence.

### v1.2.0 (Semantic Indexing & Hybrid Search)
- **Semantic Section Chunking**: Intelligent sectioning by H1-H6 tags, pricing blocks, and FAQs.
- **Hybrid Retrieval**: Fulltext keyword matching combined with cosine vector embeddings and Title Prominence boosting.
- **Roman Urdu Query Expansion**: Rule-based normalization translating casual terms (*"rate kya hai"*, *"suit dikhao"*) into clean search terms.

### v1.1.0 (Visual Themes & JSON Auto-Repair)
- Added 6 visual styling themes (Light, Midnight Dark, Glassmorphism, Luxury Gold, Minimalist, Neo-Gradient).
- Added chat header Refresh / Reset button (`#aam-refresh`) to clear session transients.
- Open Unboxed Brand Logo rendering with proportional `object-fit: contain`.
- Direct HEX code typing in color picker with instant two-way synchronization.
- Updated Google Gemini endpoint to `gemini-3.6-flash`.
- Integrated dual-layer JSON auto-repair algorithm for truncated product recommendation payloads.

### v1.0.0 (Initial Release)
- Initial release with multi-provider AI routing, WooCommerce product recommendations, shortcode, and Elementor widget.

---

## ☕ Support & Donations

If this plugin helped power your store or your clients' websites, consider buying us a coffee:

[👉 **Buy Us a Coffee on Gumroad**](https://lmdesigners.gumroad.com/coffee/)

---

## 📄 License

Arrodes AI Manager is open-source software licensed under the **GNU General Public License v2.0 or later (GPLv2)**.
