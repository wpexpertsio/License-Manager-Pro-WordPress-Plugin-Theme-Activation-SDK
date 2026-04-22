# WordPress Plugin & Theme Activation SDK (PHP Pro)

<p align="center">
  <img src="https://licensemanager.at/wp-content/uploads/2026/04/activated-license-sdk.png" alt="WordPress Plugin and Theme Licensing SDK Dashboard" width="100%">
</p>

<div align="center">

[![Purpose](https://img.shields.io/badge/Purpose-Plugin%20%26%20Theme%20Activation-blue.svg?style=for-the-badge)](https://licensemanager.at/)
[![AppSumo Ready](https://img.shields.io/badge/Partners-AppSumo%20Ready-green.svg?style=for-the-badge)](https://licensemanager.at/)
[![PRO Version](https://img.shields.io/badge/Version-PRO-gold.svg?style=for-the-badge)](https://licensemanager.at/pricing/)

</div>

---

## 🚀 The Ultimate Engine for Premium WordPress Success
**Selling a WordPress Plugin or Theme? This is your all-in-one commercial activation engine. Protect your intellectual property, manage AppSumo integration, and build a recurring revenue stream with zero commissions.**

The **License Manager Pro SDK** is specifically designed for serious developers who want independent, professional-grade license verification. It bridges the gap between your WooCommerce store and your high-quality products, giving you full control over activations, device limits, and automatic version updates.

> [!IMPORTANT]
> **Requirement:** This SDK requires the [License Manager for WooCommerce (Pro)](https://licensemanager.at/) plugin to be installed and active on your store (Server Side) to manage applications, licenses, and releases.

---

## 📊 Why Choose Self-Hosted Licensing?
Compare the freedom of using our Pro SDK vs. traditional third-party licensing platforms.

| Feature | **License Manager Pro SDK** | Third-Party Platforms |
| :--- | :---: | :---: |
| **Sales Commission** | **0% (Keep 100% Revenue)** | 7% to 27% Per Sale |
| **Data Ownership** | **100% Yours (Private)** | Shared with Platform |
| **Subscription Master** | **You (Merchant of Record)** | Platform (They Own It) |
| **Customer Database** | **Stored in YOUR DB** | Stored in THEIR DB |
| **Business Security** | **100% Independent** | High Risk (Platform Dependent) |
| **Payout Speed** | **Instant (Your WooCommerce)** | Delayed (Net-30/60) |
| **UI Control** | **Modern White-Label** | Branded by Platform |
| **AppSumo Integration** | **Native Sync Support** | Often Complex Setup |
| **Updates** | **Self-Hosted / High Speed** | Platform Dependent |

---

## 🔥 Commercial Client Experience (SDK Features)

### 🔑 Premium License Activation Page
The SDK provides a stunning, Material-inspired activation portal inside your plugin or theme. It handles license keys, emails, and real-time verification using high-speed REST API calls.
![License Activation Page](https://licensemanager.at/wp-content/uploads/2026/04/activate-license-sdk.png)

### 📊 Real-Time Device & Usage Tracking 
Enforce your licensing tiers with precision. The SDK automatically reads site limits from your backend and displays active usage progress bars directly to the user.
- **Dynamic Limits**: Site limits can be adjusted instantly from your store and reflected on user sites.
### 2. Zero-Touch AppSumo Fulfillment
The most advanced AppSumo integration for WordPress developers. The system automates the entire onboarding workflow:
- **Automatic Account Creation**: Instantly generates WordPress user accounts for AppSumo buyers.
- **Order & License Generation**: Automatically creates WooCommerce orders and unique license keys based on the purchased Tier.
- **Dynamic Tier Limits**: SDK reads the AppSumo tier (Tier 1-4) and enforces the correct activation limits instantly.
- **Instant Software Access**: Direct download links are provided to the user the moment their license is generated.

### 🔄 Intelligent One-Click Sync
Users can manually trigger a status refresh via the **"Sync License"** button. If you adjust their limits or extend their expiry in the backend, they get the updates immediately.
- **Scheduled Validation**: Silent background checks via WP-Cron keep data fresh without user intervention.
- **Fail-Safe Validation**: Keeps sites active even during temporary server downtime or timeouts.

### 🧩 Version & Update Delivery
Built-in update bridge that connects your store directly to the WordPress core update system.
- **Auto-Update Notifications**: Users receive update alerts in their dashboard just like the WP.org repository.
- **Secure Download Bridge**: Downloads are only granted to valid, active license holders.

### 🧩 Flexible Expiry & Enforcement Strategy
The SDK gives you 100% control over how you handle users who don't renew:
- **Lifetime Recognition**: Native support for perpetual licenses and unlimited activations.
- **Firm Block Enforcement**: Optionally lock premium features upon expiry to drive renewals.
- **Graceful Nudges**: Show persistent dashboard notices to remind users about upcoming expirations.

---

## 🛡️ License Manager for WooCommerce (Pro) - Server Side

### 🏗️ Unified App & Release Management
Complete control over your product lifecycle from a single centralized dashboard on your store.
- **Versions & Releases**: Push new plugin/theme versions directly to user sites from this page.
- **Global Config**: Retrieve **Public Keys** and **Application IDs** for instant SDK connection.
![Application Management](https://licensemanager.at/wp-content/uploads/2026/04/applications-page.png)

### 🛡️ Live Anti-Piracy Activation Shield
Track every site where your theme or plugin is active. Monitor domains, IP addresses, and versions in real-time. Instantly block unauthorized activations from your backend command center.
![Live Backend Tracking](https://licensemanager.at/wp-content/uploads/2026/04/activations-page.png)

### 🧠 Deep Installation & Usage Analytics
Gain comprehensive visibility into your entire install base. Segment users by product version and site health to ensure every customer is on the latest, most secure build.
![Product Install Intelligence](https://licensemanager.at/wp-content/uploads/2026/04/products-installed-on-page.png)

---

## 📦 3-Minute Integration Guide

Follow these steps to add commercial licensing to your WordPress product in minutes.

### Step 1: Upload the SDK
Copy the `lmw-client-sdk` folder into your plugin or theme's vendor directory. Your file structure should look like this:
```text
your-plugin/
├── vendor/
│   └── lmw-client-sdk/
│       ├── lmw-sdk.php (Entry File)
│       ├── src/ (Core Logic)
│       └── images/ (Assets)
├── includes/
└── your-plugin.php
```

### Step 2: Initialize the Client
Add the following boilerplate to your main plugin file (e.g., `your-plugin.php`) or your theme's `functions.php`.

```php
if ( ! function_exists( 'my_plugin_lmw' ) ) {
    /**
     * Initialize and retrieve the License Manager SDK instance.
     */
    function my_plugin_lmw() {
        global $my_plugin_lmw; // Use global variable to store the SDK instance
        
        if ( ! isset( $my_plugin_lmw ) ) {
            // 1. Load the SDK entry file
            $sdk_path = plugin_dir_path( __FILE__ ) . 'vendor/lmw-client-sdk/lmw-sdk.php';
            
            if ( file_exists( $sdk_path ) ) {
                require_once $sdk_path;
                
                // 2. Initialize the SDK with your configuration
                $my_plugin_lmw = lmw_sdk_init( array(
                    'public_key'             => 'lm_live_xxxxxxxx',               // Your Application Public Key (from Store backend)
                    'application_id'         => 1,                                // Your Application ID (from Store backend)
                    'rest_api_url'           => 'https://licensemanager.at',      // Your Store base URL where License Manager Pro is installed
                    'slug'                   => 'your-product-slug',              // Unique slug used for DB storage configuration
                    'plugin_name'            => 'My Premium Product',             // Product name displayed on the SDK UI
                    'block_after_expiration' => true,                             // If true, premium features will lock upon license expiry
                    'menu'                   => array(
                        'parent_slug' => 'your-settings-area',                   // The parent menu slug where the license page will appear
                        'page_title'  => 'Activate Pro License',                 // Title for the sub-menu item
                    ),
                ) );
            }
        }
        return $my_plugin_lmw; // Return the SDK instance for further use
    }
    
    // Initialize immediately to register core hooks, menus, and background updates
    my_plugin_lmw();
}
```

---

## 💎 Join the Independent Developer Revolution

Stop paying commissions. Own your infrastructure. Scale your sales. Start today with **License Manager Pro**.

<p align="center">
  <a href="https://licensemanager.at/pricing/">
    <img src="https://img.shields.io/badge/GET_LICENSE_MANAGER_PRO_SDK-7e22ce?style=for-the-badge&logo=wordpress" alt="WordPress Plugin Theme Activation SDK" height="50">
  </a>
</p>

<p align="center">
  <a href="https://licensemanager.at/">Live Demo</a> • 
  <a href="https://licensemanager.at/docs/">Official Docs</a> • 
  <a href="https://licensemanager.at/contact/">Premium Support</a>
</p>

---
**Keywords**: WordPress Plugin Activation, AppSumo Licensing SDK, WooCommerce Plugin Protector, Plugin Sync Server, Commercial License Manager.

<p align="right">
  <i>The Gold Standard for Premium WordPress Plugin & Theme Development.</i>
</p>
