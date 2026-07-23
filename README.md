# 🤖 Arrodes AI Manager – Free & Open-Source WordPress / WooCommerce AI Chatbot

[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![WordPress Compatibility](https://img.shields.io/badge/WordPress-v6.0%2B-0073aa.svg)](https://wordpress.org/)
[![WooCommerce Compatible](https://img.shields.io/badge/WooCommerce-v7.0%2B-[#96588a].svg)](https://woocommerce.com/)
[![Open Source](https://img.shields.io/badge/Open%20Source-%E2%9D%A4-14bda9.svg)](#)

> **Developed By:** [LM Designers](https://lmwebdesigners.com/) x [DrSmoK3y](https://www.linkedin.com/in/drsmok3y/)  
> **Status:** 100% Free & Open Source (No subscription lock, no paid API proxies, complete freedom)

---

## 📌 Project Overview & Main Goal

**Arrodes AI Manager** is a powerful, production-grade, open-source AI Assistant plugin engineered specifically for **WordPress** and **WooCommerce** websites. 

### 🎯 Main Goal
Our primary goal is to **democratize AI for every WordPress store owner, creator, and agency** by providing a completely **free, unrestricted, high-performance AI customer support and sales assistant**. Unlike commercial SaaS plugins that charge monthly subscription fees per message or lock essential settings behind expensive tiers, **Arrodes AI Manager gives you 100% control over your data, AI models, and styling**.

---

## 🌟 Key Benefits

- 💰 **100% Free & Open Source**: Zero monthly plugin fees. Connect directly to your preferred AI provider API key.
- 🛍️ **Boost WooCommerce Sales**: Real-time product search, recommendations with interactive buy cards (price, stock status, images, direct cart links).
- 🧠 **Multi-AI Engine Support**: Use **OpenRouter** (access 100+ models like Claude 3.5, Llama 3, Mistral), **Google Gemini API**, **DeepSeek API**, **Moonshot/Kimi API**, or any **Custom OpenAI-Compatible Endpoint**.
- ⚡ **Built-In Indexing & Instant Search**: Custom SQL search engine indexes your products, pages, and posts so AI answers are grounded in your actual site content.
- 🎨 **Unrestricted Visual Customization**: Custom colors, dark/light visual modes, custom dimensions, launcher animations, custom logo upload, and suggested question chips.
- 🧩 **Multiple Display Methods**: Floating offcanvas drawer, WordPress Shortcode `[arrodes_ai_chat]`, and native **Elementor** widget support.
- 🔒 **Privacy & Transparency**: Mandatory safety disclaimer line protecting user privacy (`AI can make mistakes. 🔒 Don't share any private information in the chat`).

---

## 🔥 Key Features Matrix

| Feature | Description |
| :--- | :--- |
| **Multi-Provider AI** | Toggle between OpenRouter, Google Gemini, DeepSeek, Kimi, or Custom Routers instantly without breaking settings. |
| **WooCommerce Native** | Automatically queries store inventory to display rich product cards with buy links and live pricing. |
| **Custom Offcanvas Dimensions** | Specify custom width (e.g. `380px`, `100%`) and height (e.g. `520px`) for optimal screen density. |
| **Custom Avatar Logo** | Upload your own branding image with independent logo width/height sizing controls. |
| **Visibility Control** | Easily hide the floating chat on Home, Cart, Checkout, or specific Page/Post IDs to keep checkout friction-free. |
| **Launcher Animations** | Choose attention-grabbing button animations: *Pulse*, *Bounce*, *Wiggle*, or *Static*. |
| **Suggested Chips** | Define clickable starter questions so visitors can get instant answers without typing. |
| **Support Email Integration** | Configure a dedicated customer support contact email for unanswered or complex queries. |

---

## 🚀 Quick Installation & Setup

### 1️⃣ Download Plugin ZIP
Download or clone this repository into your WordPress plugins directory:
```bash
wp-content/plugins/arrodes-ai-manager/
```

### 2️⃣ Activate via WordPress Dashboard
1. Go to **WordPress Admin → Plugins → Installed Plugins**.
2. Find **Arrodes AI Manager** and click **Activate**.

### 3️⃣ Configure Settings
1. Navigate to **Arrodes AI Manager** in the admin sidebar.
2. Under **AI Models & API**, select your provider (e.g., OpenRouter, Gemini, or DeepSeek) and enter your API Key.
3. Customise your chatbot identity, colors, launcher animation, and welcome greeting.
4. Click **Save Settings**.

---

## 💻 Shortcode & Elementor Usage

### Shortcode Embedding
Embed the chat widget inside any post, page, block, or footer widget area using the shortcode:

```html
[arrodes_ai_chat width="100%" height="550px" theme="light" primary_color="#14bda9"]
```

#### Shortcode Parameters:
- `width`: Container width (e.g., `100%`, `400px`).
- `height`: Chatbox height (e.g., `550px`, `600px`).
- `theme`: Visual theme (`light`, `dark`, or `minimal`).
- `primary_color`: Hex color code (e.g., `#14bda9`).

### Elementor Integration
If Elementor is active on your site, **Arrodes AI Chat** will automatically appear under the **Arrodes AI Manager** category in the Elementor panel. Drag and drop it onto any column or section!

---

## 🛠️ Project File Structure

```
arrodes-ai-manager/
├── arrodes-ai-manager.php         # Main WordPress plugin entry point
├── assets/
│   ├── admin.css                  # Admin dashboard styling
│   ├── admin.js                   # Admin tab switching & AJAX scripts
│   ├── chat-widget.css            # Front-end chat widget stylesheet
│   └── chat-widget.js             # Front-end live chat JS engine
├── includes/
│   ├── class_admin.php            # Admin menu, tabs & options handler
│   ├── class_api.php              # Multi-AI provider API client & router
│   ├── class_elementor.php        # Elementor Page Builder integration widget
│   ├── class_indexer.php          # WooCommerce & WP content search indexer
│   ├── class_frontend.php         # Front-end script loader & visibility rules
│   └── class_shortcode.php        # Shortcode [arrodes_ai_chat] handler
├── README.md                      # GitHub documentation
└── LICENSE                        # GPL v2 Open Source License
```

---

## 🤝 Attribution & Credits

This open-source project is proudly developed and maintained by:

- **LM Designers**: Web development & UX architecture ([Website](https://lmwebdesigners.com/))
- **DrSmoK3y**: Technical design & AI engineering ([LinkedIn](https://www.linkedin.com/in/drsmok3y/))

---

## 📄 License

**Arrodes AI Manager** is released under the **GNU General Public License v2.0 (GPLv2)**.  
You are free to use, modify, distribute, or integrate this plugin into commercial and personal projects without restrictions.
