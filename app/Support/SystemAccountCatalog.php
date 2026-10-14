<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Stable identity (code / system key) mapped to bilingual display names and role descriptions.
 * Display names are presentation only; posting logic keeps using codes and system keys.
 */
final class SystemAccountCatalog
{
    public const ACCOUNTS = [
        '1' => ['en' => 'Assets', 'ar' => 'الأصول', 'desc_en' => 'Everything the company owns or is owed.', 'desc_ar' => 'كل ما تملكه الشركة أو تستحقه لدى الغير.'],
        '2' => ['en' => 'Liabilities', 'ar' => 'الالتزامات', 'desc_en' => 'Amounts the company owes to others.', 'desc_ar' => 'المبالغ التي تلتزم الشركة بسدادها للغير.'],
        '3' => ['en' => 'Equity', 'ar' => 'حقوق الملكية', 'desc_en' => 'Owners\' capital and retained results.', 'desc_ar' => 'رأس مال الملاك والنتائج المرحّلة.'],
        '4' => ['en' => 'Expenses', 'ar' => 'المصروفات', 'desc_en' => 'Costs incurred to operate the business.', 'desc_ar' => 'التكاليف المتكبدة لتشغيل النشاط.'],
        '5' => ['en' => 'Revenue', 'ar' => 'الإيرادات', 'desc_en' => 'Income earned from sales and other activity.', 'desc_ar' => 'الدخل المحقق من المبيعات والأنشطة الأخرى.'],
        '1.1' => ['en' => 'Fixed assets', 'ar' => 'الأصول الثابتة', 'desc_en' => 'Long-lived assets such as vehicles and equipment.', 'desc_ar' => 'أصول طويلة الأجل مثل السيارات والمعدات.'],
        '1.2' => ['en' => 'Current assets', 'ar' => 'الأصول المتداولة', 'desc_en' => 'Cash, banks, customers and other short-term assets.', 'desc_ar' => 'النقدية والبنوك والعملاء وبقية الأصول قصيرة الأجل.'],
        '2.1' => ['en' => 'Current liabilities', 'ar' => 'الالتزامات المتداولة', 'desc_en' => 'Suppliers and other short-term obligations.', 'desc_ar' => 'الموردون وبقية الالتزامات قصيرة الأجل.'],
        '2.2' => ['en' => 'Long-term liabilities', 'ar' => 'الالتزامات طويلة الأجل', 'desc_en' => 'Loans and obligations due after one year.', 'desc_ar' => 'القروض والالتزامات المستحقة بعد أكثر من سنة.'],
        '3.1' => ['en' => 'Capital', 'ar' => 'رأس المال', 'desc_en' => 'Capital contributed by the owners.', 'desc_ar' => 'رأس المال المدفوع من الملاك.'],
        '3.2' => ['en' => 'Partners current', 'ar' => 'جاري الشركاء', 'desc_en' => 'Running balances with the partners.', 'desc_ar' => 'الأرصدة الجارية مع الشركاء.'],
        '4.1' => ['en' => 'G&A', 'ar' => 'المصروفات العمومية والإدارية', 'desc_en' => 'General and administrative expenses.', 'desc_ar' => 'المصروفات العمومية والإدارية.'],
        '4.2' => ['en' => 'Other expenses', 'ar' => 'مصروفات أخرى', 'desc_en' => 'Expenses outside general administration.', 'desc_ar' => 'مصروفات خارج المصروفات العمومية والإدارية.'],
        '5.1' => ['en' => 'Operating revenue', 'ar' => 'إيرادات التشغيل', 'desc_en' => 'Revenue from the core trading activity.', 'desc_ar' => 'إيرادات النشاط التجاري الأساسي.'],
        '5.2' => ['en' => 'Other revenue', 'ar' => 'إيرادات أخرى', 'desc_en' => 'Revenue outside the core activity.', 'desc_ar' => 'إيرادات خارج النشاط الأساسي.'],
        '1.INV' => ['en' => 'Inventory', 'ar' => 'المخزون', 'desc_en' => 'Stock valuation posted by purchases and sales.', 'desc_ar' => 'تقييم المخزون المرحّل من المشتريات والمبيعات.'],
        '1.VAT-IN' => ['en' => 'Recoverable input VAT', 'ar' => 'ضريبة القيمة المضافة على المشتريات (قابلة للاسترداد)', 'desc_en' => 'VAT paid on purchases that can be recovered.', 'desc_ar' => 'ضريبة مدفوعة على المشتريات يمكن استردادها.'],
        '2.VAT-OUT' => ['en' => 'Output VAT payable', 'ar' => 'ضريبة القيمة المضافة المستحقة على المبيعات', 'desc_en' => 'VAT collected on sales and owed to the tax authority.', 'desc_ar' => 'ضريبة محصلة على المبيعات ومستحقة للجهة الضريبية.'],
        '4.COGS' => ['en' => 'Cost of goods sold', 'ar' => 'تكلفة البضاعة المباعة', 'desc_en' => 'Inventory cost released when goods are sold.', 'desc_ar' => 'تكلفة المخزون المحرَّر عند بيع البضاعة.'],
        '3.OPENING' => ['en' => 'Opening balance equity', 'ar' => 'حقوق الملكية للأرصدة الافتتاحية', 'desc_en' => 'Offset account for opening balances.', 'desc_ar' => 'حساب مقابل للأرصدة الافتتاحية.'],
        '4.2.9001' => ['en' => 'Fleet operating expenses', 'ar' => 'مصروفات تشغيل الأسطول', 'desc_en' => 'Running costs of the vehicle fleet.', 'desc_ar' => 'تكاليف تشغيل أسطول المركبات.'],
        '4.2.9002' => ['en' => 'Vehicle maintenance expense', 'ar' => 'مصروفات صيانة المركبات', 'desc_en' => 'Maintenance and repair of vehicles.', 'desc_ar' => 'صيانة المركبات وإصلاحها.'],
        '2.1.9001' => ['en' => 'Fleet accrued payable', 'ar' => 'مستحقات الأسطول', 'desc_en' => 'Fleet costs incurred and not yet paid.', 'desc_ar' => 'تكاليف الأسطول المستحقة وغير المدفوعة.'],
    ];

    /** @return array{en: string, ar: string, desc_en: string, desc_ar: string}|null */
    public static function forCode(string $code): ?array
    {
        return self::ACCOUNTS[$code] ?? null;
    }
}
