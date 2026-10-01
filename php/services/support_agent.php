<?php

declare(strict_types=1);

class SupportAgent
{
    private array $context;
    private array $knowledgeBase;

    public function __construct()
    {
        $company = (string) (getenv('COMPANY_NAME') ?: 'DATAPOINT Technologies');
        $this->context = [
            'company' => $company,
            'address' => (string) (getenv('COMPANY_ADDRESS') ?: 'G 32 Shayas Residence, Jamshoro Road, Hyderabad Sindh'),
            'phone' => (string) (getenv('COMPANY_PHONE') ?: '+92-316-7788990'),
            'email' => (string) (getenv('COMPANY_EMAIL') ?: 'info@datapointtechnology.com'),
            'sales_email' => 'sales@datapointtechnology.com',
            'website' => 'http://datapointtechnology.com',
            'hours' => 'Monday–Saturday, 9:00 AM – 7:00 PM',
        ];
        $this->knowledgeBase = $this->loadKnowledgeBase();
    }

    private function loadKnowledgeBase(): array
    {
        return [
            'greeting' => [
                'keywords' => ['hi', 'hello', 'hey', 'salam', 'assalam', 'good morning', 'good afternoon', 'good evening', 'start', 'help'],
                'response' => "👋 Assalam-o-Alaikum! Welcome to {$this->context['company']} Support.\n\n"
                    . "I can help you with:\n"
                    . "• 🖥️ Products & Pricing (Laptops, Desktops, Networking, CCTV, Accessories)\n"
                    . "• 🛠️ Services (Software Dev, Networking, Web Development, IT Support)\n"
                    . "• 📄 Invoices, Estimates & Quotations\n"
                    . "• 💳 Payments & Tax Information\n"
                    . "• 🚚 Delivery & Warranty Policies\n"
                    . "• 📞 Contact & Support\n\n"
                    . "Just type your question and I'll guide you!",
            ],
            'payment_methods' => [
                'keywords' => ['payment', 'pay', 'bank', 'transfer', 'jazzcash', 'easypaisa', 'cheque', 'cash', 'how to pay', 'payment method'],
                'response' => "💳 **Payment Methods:**\n\n"
                    . "We accept the following:\n"
                    . "1. 🏦 Bank Transfer (preferred for large orders)\n"
                    . "2. 📝 Crossed Cheque (payable to {$this->context['company']})\n"
                    . "3. 💵 Cash (in-office payments)\n"
                    . "4. 📱 JazzCash\n"
                    . "5. 📱 Easypaisa\n\n"
                    . "📌 Bank details are printed on every invoice.\n"
                    . "📌 Always use your Invoice # as payment reference.\n"
                    . "📌 Payment terms: Net 30 days (corporate clients).\n"
                    . "📌 Late payment fee: 2% per month on overdue amounts.",
            ],
            'tax_query' => [
                'keywords' => ['tax', 'gst', 'sales tax', 'ntn', 'strn', 'withholding', 'wht', 'fed', 'fbr', 'tax invoice'],
                'response' => "🧾 **Tax Information:**\n\n"
                    . "• All invoices include **17% General Sales Tax (GST)** as per Pakistan Sales Tax Act 1990.\n"
                    . "• Our **NTN** and **STRN** are printed on all tax invoices.\n"
                    . "• **Withholding Tax (WHT)** at 4% is applied where applicable as per FBR rules.\n"
                    . "• **FED (Federal Excise Duty)** at 5% applied on applicable services.\n"
                    . "• Tax invoices are issued for all registered business clients.\n\n"
                    . "📌 For tax exemption certificates or special tax treatment, contact our accounts team.",
            ],
            'invoice_query' => [
                'keywords' => ['invoice', 'bill', 'receipt', 'statement', 'invoice status', 'invoice copy'],
                'response' => "📄 **Invoice Information:**\n\n"
                    . "• Invoices are generated after approval of estimates/quotations.\n"
                    . "• You will receive your invoice via **email** and **WhatsApp**.\n"
                    . "• PDF copies are always available on request.\n"
                    . "• Invoice statuses: Draft → Sent → Partially Paid → Paid\n\n"
                    . "📌 To check your invoice status, provide your Invoice # to our accounts team.\n"
                    . "📌 For duplicate invoice copies: {$this->context['sales_email']}\n"
                    . "📌 Payment due date is printed on every invoice.",
            ],
            'estimate_query' => [
                'keywords' => ['estimate', 'quotation', 'quote', 'proposal', 'how much', 'cost', 'rate'],
                'response' => "📋 **Estimates & Quotations:**\n\n"
                    . "• We provide **free estimates** for all products and services.\n"
                    . "• Estimates are valid for **15 days** from issue date.\n"
                    . "• Once approved by client, estimates are converted to sales invoices.\n"
                    . "• Estimates include itemized pricing with GST breakdown.\n\n"
                    . "📌 To request a quotation:\n"
                    . "   1. Tell us what products/services you need\n"
                    . "   2. Specify quantities\n"
                    . "   3. We'll prepare and send the estimate within 24 hours\n\n"
                    . "📧 Email: {$this->context['sales_email']}\n"
                    . "📞 Call: {$this->context['phone']}",
            ],
            'delivery_query' => [
                'keywords' => ['delivery', 'shipping', 'dispatch', 'when', 'how long', 'timeline', 'days'],
                'response' => "🚚 **Delivery Information:**\n\n"
                    . "▪ Hardware & Products\n"
                    . "   • In-stock items: 1–3 working days\n"
                    . "   • Import/order items: 7–14 working days\n"
                    . "   • Bulk orders: Timeline discussed at order time\n\n"
                    . "▪ Services\n"
                    . "   • IT Support (on-site): Same day or next day\n"
                    . "   • Network installation: 1–5 days depending on scope\n"
                    . "   • CCTV installation: 1–3 days\n"
                    . "   • Software projects: As per agreed project timeline\n\n"
                    . "📌 You will be notified via SMS/WhatsApp once your order is dispatched.\n"
                    . "📌 Delivery charges may apply outside Hyderabad.",
            ],
            'warranty_return' => [
                'keywords' => ['warranty', 'return', 'refund', 'replace', 'replacement', 'defective', 'damaged', 'broken', 'guarantee'],
                'response' => "🔄 **Warranty & Return Policy:**\n\n"
                    . "▪ Hardware Products\n"
                    . "   • Dead on arrival (DOA): Replacement within 48 hours\n"
                    . "   • Defective items: 7-day return/replacement policy\n"
                    . "   • Manufacturer warranty: As per brand (1–3 years)\n\n"
                    . "▪ Assembled PCs & Custom Builds\n"
                    . "   • 6-month local warranty on assembly & components\n\n"
                    . "▪ Software & Services\n"
                    . "   • Non-refundable once delivered\n"
                    . "   • Bug fixes & support included post-delivery\n\n"
                    . "▪ CCTV & Networking Equipment\n"
                    . "   • 1-year warranty on equipment\n"
                    . "   • Installation warranty: 3 months\n\n"
                    . "📌 To initiate a return, contact us with your Invoice # and issue description.\n"
                    . "📞 {$this->context['phone']}",
            ],
            'contact_query' => [
                'keywords' => ['contact', 'address', 'location', 'office', 'reach', 'call', 'visit', 'where', 'find'],
                'response' => "📞 **Contact {$this->context['company']}:**\n\n"
                    . "📍 {$this->context['address']}\n"
                    . "📞 {$this->context['phone']}\n"
                    . "📧 {$this->context['email']}\n"
                    . "📧 {$this->context['sales_email']} (for orders & quotes)\n"
                    . "🌐 {$this->context['website']}\n\n"
                    . "🕒 Business Hours: {$this->context['hours']}\n\n"
                    . "📌 For urgent support, call directly.\n"
                    . "📌 For quotes & orders, email sales team.",
            ],
            'about' => [
                'keywords' => ['about', 'who are you', 'company', 'datapoint', 'background', 'experience', 'history', 'profile'],
                'response' => "🏢 **About {$this->context['company']}:**\n\n"
                    . "{$this->context['company']} is a leading IT solutions provider based in Hyderabad, Sindh.\n\n"
                    . "▪ What We Do:\n"
                    . "   • Supply of computers, laptops & IT hardware\n"
                    . "   • Networking & infrastructure solutions\n"
                    . "   • CCTV & security systems\n"
                    . "   • Custom software & web development\n"
                    . "   • IT support & maintenance services\n\n"
                    . "▪ Why Choose Us:\n"
                    . "   • Authorized dealers for major brands\n"
                    . "   • Experienced technical team\n"
                    . "   • After-sales support & AMC\n"
                    . "   • Competitive pricing\n"
                    . "   • Serving businesses across Sindh\n\n"
             ],
        ];
    }

    public function queryPrices(PDO $db, ?string $searchTerm = null, ?string $category = null): ?string
    {
        $where = ['is_active = 1'];
        $params = [];
        if ($searchTerm !== null && $searchTerm !== '') {
            $where[] = 'name LIKE :search';
            $params['search'] = '%' . $searchTerm . '%';
        }
        if ($category !== null && $category !== '') {
            $where[] = 'category LIKE :cat';
            $params['cat'] = '%' . $category . '%';
        }
        $sql = 'SELECT * FROM price_list WHERE ' . implode(' AND ', $where) . ' ORDER BY category ASC, name ASC LIMIT 50';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) {
            return null;
        }

        $groups = [];
        foreach ($items as $item) {
            $cat = !empty($item['category']) ? $item['category'] : 'General';
            $groups[$cat][] = $item;
        }

        $lines = [];
        foreach ($groups as $cat => $catItems) {
            $lines[] = "\n▪ **{$cat}**";
            foreach (array_slice($catItems, 0, 10) as $i) {
                $price = number_format((float) ($i['unit_price'] ?? 0), 2);
                $unit = $i['unit'] ?? 'pcs';
                $lines[] = "   • {$i['name']} — PKR {$price}/{$unit}";
            }
        }
        return implode("\n", $lines);
    }

    public function detectSendInvoice(string $message): ?array
    {
        $msg = strtolower(trim($message));
        if ($msg === '' || preg_match('/^(how|what|why|when|where|explain|can you tell)\b/', $msg)) {
            return null;
        }

        $hasWhatsApp = str_contains($msg, 'whatsapp') || str_contains($msg, 'whats app') || str_contains($msg, 'watsapp');
        $hasInvoice = str_contains($msg, 'invoice') || str_contains($msg, 'bill');
        if (!($hasWhatsApp && $hasInvoice)) {
            return null;
        }

        $hasSend = false;
        foreach (['send', 'share', 'forward', 'whatsapp me', 'on whatsapp', 'to whatsapp', 'via whatsapp'] as $v) {
            if (str_contains($msg, $v)) {
                $hasSend = true;
                break;
            }
        }
        if (!$hasSend) {
            return null;
        }

        $invoiceRef = null;
        if (preg_match('/inv[\s-]*(\d{4,6})[\s-]*(\d+)/i', $msg, $m)) {
            $invoiceRef = sprintf('INV-%s-%s', $m[1], $m[2]);
        } elseif (preg_match('/(?:invoice|bill)\s*#?\s*(\d+)\b/i', $msg, $m)) {
            $invoiceRef = $m[1];
        }

        $useLatest = false;
        foreach (['last', 'latest', 'recent', 'most recent'] as $w) {
            if (str_contains($msg, $w)) {
                $useLatest = true;
                break;
            }
        }

        return ['invoice_ref' => $invoiceRef, 'use_latest' => $useLatest, 'message' => $message];
    }

    public function getResponse(string $userMessage, ?PDO $db = null): string
    {
        $msg = strtolower(trim($userMessage));
        if ($msg === '') {
            return "Please type your query. I'm here to help!";
        }

        $pricingKeywords = [
            'price', 'pricing', 'cost', 'rate', 'rates', 'pricelist', 'price list',
            'laptop', 'laptops', 'desktop', 'desktops', 'pc', 'computer',
            'router', 'switch', 'networking', 'cctv', 'camera',
            'printer', 'monitor', 'accessories', 'ssd', 'hard drive',
            'products', 'product', 'item', 'items', 'what is the price',
            'how much', 'pkr'
        ];
        $isPricing = false;
        foreach ($pricingKeywords as $kw) {
            if (str_contains($msg, $kw)) {
                $isPricing = true;
                break;
            }
        }

        $categoryMap = [
            'laptop' => 'laptop', 'desktop' => 'desktop', 'pc' => 'desktop',
            'network' => 'networking', 'router' => 'networking', 'switch' => 'networking',
            'cctv' => 'cctv', 'camera' => 'cctv', 'surveillance' => 'cctv',
            'accessor' => 'accessories', 'printer' => 'accessories', 'monitor' => 'accessories',
            'storage' => 'accessories', 'software' => 'software', 'service' => 'services',
            'it support' => 'services', 'maintenance' => 'services',
        ];
        $matchedCategory = null;
        foreach ($categoryMap as $kw => $cat) {
            if (str_contains($msg, $kw)) {
                $matchedCategory = $cat;
                break;
            }
        }

        if ($isPricing && $db !== null) {
            $priceResp = $this->queryPrices($db, null, $matchedCategory);
            if ($priceResp !== null) {
                $header = "💰 **Live Prices from our Database**\n_(Prices effective as of today)_\n";
                if ($matchedCategory) {
                    $header = "💰 **Live " . ucfirst($matchedCategory) . " Prices**\n(_Prices effective as of today_)\n";
                }
                return $header . $priceResp . "\n\n📌 Prices are updated daily. Contact us for bulk discounts.\n📞 {$this->context['phone']} | 📧 {$this->context['sales_email']}";
            }
            if ($matchedCategory) {
                return "🔍 No prices found for '{$matchedCategory}'.\n\nPlease check back later or contact us:\n📞 {$this->context['phone']}\n📧 {$this->context['sales_email']}";
            }
        }

        $bestMatch = null;
        $maxScore = 0;
        foreach ($this->knowledgeBase as $cat => $data) {
            $score = 0;
            foreach ($data['keywords'] as $kw) {
                if (str_contains($msg, $kw)) {
                    $score++;
                }
            }
            if ($score > $maxScore) {
                $maxScore = $score;
                $bestMatch = $data['response'];
            }
        }

        if ($bestMatch !== null && $maxScore > 0) {
            return $bestMatch;
        }

        return "Thank you for your query! I'm not sure I have a specific answer for that.\n\n"
            . "You can ask me about:\n"
            . "• Laptops, Desktops, Networking, CCTV, Accessories\n"
            . "• Software Development, IT Support, Web Development\n"
            . "• Invoices, Payments, Estimates, Delivery, Warranty\n\n"
            . "Or contact us directly:\n"
            . "📞 {$this->context['phone']}\n"
            . "📧 {$this->context['email']}";
    }

    public function getHelpTopics(): array
    {
        return [
            ['title' => 'Products & Pricing', 'description' => 'Inquire about laptops, desktops, networking, and live prices'],
            ['title' => 'Invoices & Billing', 'description' => 'Check invoice status or ask to receive your invoice on WhatsApp'],
            ['title' => 'Estimates & Quotations', 'description' => 'Get quotation terms, timelines, and request proposals'],
            ['title' => 'Payments & Tax', 'description' => 'Accepted payment methods, GST (17%), NTN, and WHT rules'],
            ['title' => 'Warranty & Returns', 'description' => 'Warranty coverage on hardware, assembled PCs, and CCTV'],
            ['title' => 'Contact & Office Hours', 'description' => 'Office address in Hyderabad, phone, email, and working hours'],
        ];
    }
}

function process_voice_quotation(string $text, ?PDO $db = null): array
{
    $lower = strtolower($text);
    $detectedItems = [];

    $catalog = [
        ['pattern' => '/(\d+)?\s*(?:laptop|notebook)s?/i', 'name' => 'Dell Latitude Laptop i5', 'price' => 125000, 'category' => 'Laptop'],
        ['pattern' => '/(\d+)?\s*(?:desktop|pc|computer)s?/i', 'name' => 'Custom Core i7 Desktop PC', 'price' => 95000, 'category' => 'Desktop'],
        ['pattern' => '/(\d+)?\s*(?:printer)s?/i', 'name' => 'HP LaserJet Pro Printer', 'price' => 58000, 'category' => 'Printer'],
        ['pattern' => '/(\d+)?\s*(?:cctv|camera)s?/i', 'name' => 'Hikvision 4MP CCTV Camera', 'price' => 8500, 'category' => 'CCTV'],
        ['pattern' => '/(\d+)?\s*(?:router|wifi)s?/i', 'name' => 'TP-Link Gigabit Dual-Band Router', 'price' => 12500, 'category' => 'Networking'],
        ['pattern' => '/(\d+)?\s*(?:switch)es?/i', 'name' => 'Cisco 24-Port Gigabit Switch', 'price' => 38000, 'category' => 'Networking'],
    ];

    $subtotal = 0.0;
    foreach ($catalog as $item) {
        if (preg_match($item['pattern'], $lower, $m)) {
            $qty = !empty($m[1]) ? (int) $m[1] : 1;
            $lineTotal = $qty * $item['price'];
            $subtotal += $lineTotal;
            $detectedItems[] = [
                'name' => $item['name'],
                'quantity' => $qty,
                'unit_price' => $item['price'],
                'total' => $lineTotal,
                'category' => $item['category'],
            ];
        }
    }

    $taxRate = 17.0;
    $taxAmount = $subtotal * ($taxRate / 100);
    $totalAmount = $subtotal + $taxAmount;

    return [
        'transcript' => $text,
        'intent' => count($detectedItems) > 0 ? 'quotation' : 'general_query',
        'items' => $detectedItems,
        'subtotal' => round($subtotal, 2),
        'tax_rate' => $taxRate,
        'tax_amount' => round($taxAmount, 2),
        'total_amount' => round($totalAmount, 2),
        'status' => 'ready',
    ];
}
