<?php

namespace Database\Seeders;

use App\Models\SupplierActivityCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupplierActivityCategorySeeder extends Seeder
{

    /**
     * Run the database seeds.
     */

    public function run(): void
    {
        DB::table('supplier_activity_categories')->delete();


        /*$constructionMaterials = SupplierActivityCategory::create(['name' => 'MasalehSakhtemani']);
        $decorativeMaterials = SupplierActivityCategory::create(['name' => 'TazeenatSakhtemani']);
        $fittingsMaterials = SupplierActivityCategory::create(['name' => 'YaraghAlat']);
        $electronicEquipments = SupplierActivityCategory::create(['name' => 'TajhizatElectronic']);
        $bathroomEquipments = SupplierActivityCategory::create(['name' => 'TajhizatHamamDastShoie']);
        $kitchenEquipments = SupplierActivityCategory::create(['name' => 'TajhizatAshpazKhane']);


        $childCategorys1 = [
            'ایزوگام و عایق',
            'سنگ',
            'آهن آلات',
            'شن و ماسه',
            'سیمان و گچ',
            'آجر و سفال',
            'شیشه و نما',
            'موزاییک و کفپوش',
            'ضایعات فلزی'
        ];

        foreach ($childCategorys1 as $name) {
            SupplierActivityCategory::create(['name' => $name, 'parent_id' => $constructionMaterials->id, 'slug' => Str::slug($name)]);
        }


        $childCategorys2 = [
            'دیوارپوش',
            'درب و پنجره',
            'کاشی و سرامیک',
            'پروفیل',
            'پارکت و کفپوش',
            'قرنیز',
            'شومینه',
            'دستگاه واکس زن',
            'کفپوش سه بعدی آسانسور',
            'چمن مصنوعی',
            'ابزار پیش ساخته'
        ];

        foreach ($childCategorys2 as $name) {
            SupplierActivityCategory::create(['name' => $name, 'parent_id' => $decorativeMaterials->id, 'slug' => Str::slug($name)]);
        }

        $childCategorys3 = [
            "یراق آلات",
            "دستگیره های کمد و کابینت",
            "لوله های کابینت و درب های ساختمانی",
            "ریل کشو و کابینت",
            "پایه های کابینتی",
            "آرام بند",
            "تقسیم کننده های داخل کابینت",
            "قفل و مغزی درب",
            "یراق آلات تکمیلی",
            "دستگیره در",
            "تجهیزات تردد",
            "کف شور",
            "لوله پلی اتیلن",
            "لوله پلیکا",
            "لوله سفید",
            "لوله 5 لایه"
        ];

        foreach ($childCategorys3 as $name) {
            SupplierActivityCategory::create(['name' => $name, 'parent_id' => $fittingsMaterials->id, 'slug' => Str::slug($name)]);
        }

        $childCategorys4 = [
            "تجهیزات اکترونیک",
            "کلید و پریز",
            "آیفون تصویری",
            "کلید مینیاتوری",
            "فیوز و کابل برق",
            "چراغ توکار",
            "چراغ پروژکتوری",
            "چراغ روکار",
            "کنتاکتور",
            "صفحه شبخواب",
            "رله",
            "خازن",
            "ترانس",
            "حفاظت",
            "لیمیت سوئیچ",
            "پوش باتن"
        ];

        foreach ($childCategorys4 as $name) {
            SupplierActivityCategory::create(['name' => $name, 'parent_id' => $electronicEquipments->id, 'slug' => Str::slug($name)]);
        }

        $childCategorys5 = [
            "تجهیزات حمام و دستشویی",
            "کابین سونا",
            "کابین دوش",
            "وان حمام و جکوزی",
            "روشویی",
            "فلاش تانک",
            "پارتیشن وان",
            "پارتیشن زمینی حمام",
            "توالت ایرانی",
            "توالت فرنگی",
            "زیردوشی",
            "حمام توکار",
            "هواکش خانگی",
            "حوله خشک کن",
            "دستشویی توکار",
            "دستگاه دست خشک کن",
            "کابینت سرویس بهداشتی",
            "بیده توالت فرنگی",
            "استخر و سونا"
        ];
        foreach ($childCategorys5 as $name) {
            SupplierActivityCategory::create(['name' => $name, 'parent_id' => $bathroomEquipments->id, 'slug' => Str::slug($name)]);
        }

        $childCategorys6 = [
            "تجهیزات آشپزخانه",
            "سینک ظرفشویی",
            "فر توکار",
            "هود",
            "گاز رومیزی",
            "کابینت آشپرخانه"
        ];
        foreach ($childCategorys6 as $name) {
            SupplierActivityCategory::create(['name' => $name, 'parent_id' => $kitchenEquipments->id, 'slug' => Str::slug($name)]);
        }*/

        $StiffeningMaterials = SupplierActivityCategory::create([
            'name' => 'مصالح سفتکاری',
            'slug' => Str::slug('Masaleh Seftkari', '-')
        ]);
        $childCategory1 = [
            'سیمان',
            'شن و ماسه',
            'بتن',
            'آجر',
            'بلوک',
            'میلگرد',
            'ورق',
            'نبشی و تیرآهن',
            'شبکه مش',
            'سنگ لاشه',
            'سنگ ساختمانی',
            'قلوه سنگ',
            'چوب خام',
            'تخته چند لایه',
            'نئوپان',
            'عایق رطوبتی',
            'عایق حرارتی',
            'عایق صوتی',
            'ملات ماسه سیمان',
            'ملات گچ وخاک',
            'ملات آهک',
            'چسب های ساختمانی',
            'قالب فلزی',
            'قالب چوبی',
            'پلی وود',
            'تیرچه، بلوک و فوم',
            'خاک رس(کائولن)',
            'گچ',
            'آهک'
        ];
        foreach ($childCategory1 as $childName) {
            SupplierActivityCategory::create([
                'name' => $childName,
                'slug' => '',
                'parent_id' => $StiffeningMaterials->id
            ]);
        }
        /*$stoneCategory = SupplierActivityCategory::where('name', 'سنگ')->first();
        $stoneSubCategories = [
            'سنگ مرمر',
            'سنگ گرانیت',
            'سنگ آهک'
        ];

        foreach ($stoneSubCategories as $subCategoryName) {
            SupplierActivityCategory::create([
                'name' => $subCategoryName,
                'slug' => '',
                'parent_id' => $stoneCategory->id
            ]);
        }*/

        $JoineryMaterials = SupplierActivityCategory::create([
            'name' => 'مصالح نازک کاری',
            'slug' => Str::slug('Joinery Materials', '-')
        ]);
        $childCategory2 = [
            'گچ و گچ کاری',
            'رنگ و پوشش های تزئینی',
            'کاغذ دیواری',
            'پنل های سقفی و دیواری',
            'کناف',
            'سرامیک و کاشی',
            'سنگ های طبیعی و مصنوعی',
            'کف پوش های چوبی و لمینت',
            'کف پوش های PVC و وینیل',
            'موکت',
            'سنگ های نما',
            'آجرهای نما',
            'نماهای کامپوزیت',
            'نماهای شیشه ای',
            'نماهای چوبی یا ترمو وود',
            'شیرآلات',
            'روشویی و سینک',
            'کابین دوش و وان',
            'اینه ها و تجهیزات جانبی',
            'کابینت های آشپزخانه',
            'دکورهای چوبی',
            'کمدهای دیواری و اطاق لباس',
            'شیشه های ساده و دکوراتیو',
            'اینه ها',
            'لوسترها و چراغ های دکوراتیو',
            'پنل های روشنایی',
            'سیستم های نورپردازی مخفی',


        ];
        foreach ($childCategory2 as $childName2) {
            SupplierActivityCategory::create([
                'name' => $childName2,
                'slug' => '',
                'parent_id' => $JoineryMaterials->id
            ]);
        }

        $InsulationMaterials = SupplierActivityCategory::create([
            'name' => 'مصالح تاسیسات مکانیکی',
            'slug' => Str::slug('Insulation Materials', '-')
        ]);
        $childCategory3 = [
            "پشم سنگ",
            "پشم شیشه",
            "پلی استایرن XPS و EPS",
            "پلی یورتان PU",
            "عایق های طبیعی",
            "فوم آکوستیک",
            "پانل‌های چوبی یا MDF آکوستیک",
            "پانل‌های گچی یا هسته عایق صوتی",
            "موکت یا کف پوش عایق صدا",
            "ایزوگام",
            "قیر و گونی",
            "عایق های مایع",
            "ژئو ممبران",
            "پانل‌های ساندویچی",
            "عایق های نانو",
            "فویل آلومینیومی",
            "عایق های حرارتی با پوشش فویلی",
            "ژل های عایق حرارتی",
            "عایق های خلا",
            "بتن عایق",
        ];
        foreach ($childCategory3 as $childName3) {
            SupplierActivityCategory::create([
                'name' => $childName3,
                'slug' => '',
                'parent_id' => $InsulationMaterials->id
            ]);
        }

        $MechanicalInstallationsMaterials = SupplierActivityCategory::create([
            'name' => 'مصالح تاسیسات مکانیکی',
            'slug' => Str::slug('Mechanical Installations Materials', '-')
        ]);
        $childCategory4 = [
            "لوله های فلزی",
            "لوله های غیر فلزی",
            "اتصالات لوله ها",
            "شیر آلات",
            "سیستم های گرمایشی",
            "سیستم های سرمایشی",
            "سیستم های تهویه",
            "لوله های فاضلابی",
            "اتصالات فاضلاب",
            "منهولها و چاهک ها",
            "پمپ های فاضلاب",
            "مخازن آب",
            "پمپ ها",
            "شیرآلات کنترلی",
            "لوله های گاز",
            "شیر آلات گازی",
            "تجهیزات ایمنی",
            "لوله های اطفای حریق",
            "اسپرینکلرها",
            "مخازن ذخیره آب آتش نشانی",
            "شیرهای آتش نشانی",
            "سیستم های کنترل خودکار",
            "سیستم های انرژی تجدید پذیر",
            "آسانسور و تجهیزات جانبی"
        ];
        foreach ($childCategory4 as $childName4) {
            SupplierActivityCategory::create([
                'name' => $childName4,
                'slug' => '',
                'parent_id' => $MechanicalInstallationsMaterials->id
            ]);
        }

        $ElectricalInstallationMaterials = SupplierActivityCategory::create([
            'name' => 'مصالح تاسیسات الکتریکی',
            'slug' => Str::slug('Electrical Installation Materials', '-')
        ]);
        $childCategory5 = [
            "سیم ها",
            "کابل ها",
            "لوله های عبور سیم و کابل",
            "تابلوهای برق",
            "کلیدها و قطع کننده ها",
            "پریزها و سوکت ها",
            "کابل گیرها و ترمینال ها",
            "باکس های توزیع",
            "چراغ ها",
            "کلیدها و دیمرها",
            "سیستم های نورپردازی",
            "آسانسور و تجهیزات جانبی",
            "سیستم ارتینگ",
            "محافظت در برابر صاعقه",
            "سیستم های هشدار",
            "کنترل هوشمند ساختمان BMS",
            "تایمرها و کنترل کننده ها",
            "خانه های هوشمند",
            "ژنراتورها",
            "سیستم UPS",
            "پنل های خورشیدی",
            "باتری ها و ذخیره سازها",
            "ترانسفورماتورها",
            "کنتورها",
        ];
        foreach ($childCategory5 as $childName5) {
            SupplierActivityCategory::create([
                'name' => $childName5,
                'slug' => '',
                'parent_id' => $ElectricalInstallationMaterials->id
            ]);
        }

        $AdvancedMaterials = SupplierActivityCategory::create([
            'name' => 'مصالح نوین و پیشرفته',
            'slug' => Str::slug('Advanced Materials', '-')
        ]);
        $childCategory6 = [
            "بتن خودتراکم (SCC)",
            "بتن سبک",
            "بتن گازی (AAC) یا بتن‌های پلیمری برای کاهش وزن سازه",
            "بتن شفاف (Translucent Concrete)",
            "بتن سبز",
            "بتن فوق‌مقاوم (UHPC)",
            "عایق‌های نانو",
            "ژل‌های سیلیکا (Aerogel)",
            "پانل‌های عایق خلأ (VIPs)",
            "عایق‌های حرارتی انعکاسی",
            "شیشه‌های الکتروکرومیک",
            "شیشه‌های فوتوکرومیک",
            "شیشه‌های خودتمیزشونده",
            "شیشه‌های چندلایه ضد گلوله و ضد انفجار",
            "کامپوزیت‌های پلیمری",
            "کامپوزیت‌های چوب-پلاستیک (WPC)",
            "الیاف FRP (Fiber Reinforced Polymer)",
            "مواد بازیافتی",
            "چوب‌های مهندسی‌شده",
            "آجرهای زیستی",
            "آلیاژهای هوشمند",
            "آلومینیوم کامپوزیت",
            "فولادهای سبک و فوق‌مقاوم",
            "رنگ‌های نانو",
            "پوشش‌های نانو برای سطوح",
            "نانوذرات در بتن",
            "پانل‌های خورشیدی",
            "پانل‌های ساندویچی",
            "پانل‌های سه‌بعدی (3D Panels)",
            "کف‌پوش‌های رزینی",
            "پوشش‌های اپوکسی",
            "کف‌پوش‌های هوشمند",
            "خانه‌های هوشمند",
            "سیستم‌های تهویه هوشمند",
            "مصالح تغییرپذیر (PCM - Phase Change Materials)",
            "بتن سبک گازی (AAC)",
            "بلوک‌های هبلکس یا پرلیت",
            "فوم‌های پلی‌یورتان",

        ];
        foreach ($childCategory6 as $childName6) {
            SupplierActivityCategory::create([
                'name' => $childName6,
                'slug' => '',
                'parent_id' => $AdvancedMaterials->id
            ]);
        }

    }
}
