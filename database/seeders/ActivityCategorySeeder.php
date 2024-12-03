<?php

namespace Database\Seeders;

use App\Models\ActivityCategory;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ActivityCategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */

    public function run(): void
    {
        /*$constructionMaterials = ActivityCategory::create(['name' => 'MasalehSakhtemani']);
        $decorativeMaterials = ActivityCategory::create(['name' => 'TazeenatSakhtemani']);
        $fittingsMaterials = ActivityCategory::create(['name' => 'YaraghAlat']);
        $electronicEquipments = ActivityCategory::create(['name' => 'TajhizatElectronic']);
        $bathroomEquipments = ActivityCategory::create(['name' => 'TajhizatHamamDastShoie']);
        $kitchenEquipments = ActivityCategory::create(['name' => 'TajhizatAshpazKhane']);


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
            ActivityCategory::create(['name' => $name, 'parent_id' => $constructionMaterials->id, 'slug' => Str::slug($name)]);
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
            ActivityCategory::create(['name' => $name, 'parent_id' => $decorativeMaterials->id, 'slug' => Str::slug($name)]);
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
            ActivityCategory::create(['name' => $name, 'parent_id' => $fittingsMaterials->id, 'slug' => Str::slug($name)]);
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
            ActivityCategory::create(['name' => $name, 'parent_id' => $electronicEquipments->id, 'slug' => Str::slug($name)]);
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
            ActivityCategory::create(['name' => $name, 'parent_id' => $bathroomEquipments->id, 'slug' => Str::slug($name)]);
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
            ActivityCategory::create(['name' => $name, 'parent_id' => $kitchenEquipments->id, 'slug' => Str::slug($name)]);
        }*/

        $constructionMaterials = ActivityCategory::create([
            'name' => 'MasalehSakhtemani',
            'slug' => Str::slug('Masaleh Sakhtemani', '-')
        ]);
        $childCategory1 = [
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
        foreach ($childCategory1 as $childName) {
            ActivityCategory::create([
                'name' => $childName,
                'slug' => '',
                'parent_id' => $constructionMaterials->id
            ]);
        }
        $stoneCategory = ActivityCategory::where('name', 'سنگ')->first();
        $stoneSubCategories = [
            'سنگ مرمر',
            'سنگ گرانیت',
            'سنگ آهک'
        ];

        foreach ($stoneSubCategories as $subCategoryName) {
            ActivityCategory::create([
                'name' => $subCategoryName,
                'slug' => '',
                'parent_id' => $stoneCategory->id
            ]);
        }

        $decorativeMaterials = ActivityCategory::create([
            'name' => 'TazeenatSakhtemani',
            'slug' => Str::slug('Tazeenat Sakhtemani', '-')
        ]);
        $childCategory2 = [
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
        foreach ($childCategory2 as $childName2) {
            ActivityCategory::create([
                'name' => $childName2,
                'slug' => '',
                'parent_id' => $decorativeMaterials->id
            ]);
        }

        $fittingsMaterials = ActivityCategory::create([
            'name' => 'YaraghAlat',
            'slug' => Str::slug('Yaragh Alat', '-')
        ]);
        $childCategory3 = [
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
        foreach ($childCategory3 as $childName3) {
            ActivityCategory::create([
                'name' => $childName3,
                'slug' => '',
                'parent_id' => $fittingsMaterials->id
            ]);
        }

        $electronicEquipments = ActivityCategory::create([
            'name' => 'TajhizatElectronic',
            'slug' => Str::slug('Tajhizat Electronic', '-')
        ]);
        $childCategory4 = [
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
        foreach ($childCategory4 as $childName4) {
            ActivityCategory::create([
                'name' => $childName4,
                'slug' => '',
                'parent_id' => $electronicEquipments->id
            ]);
        }

        $bathroomEquipments = ActivityCategory::create([
            'name' => 'TajhizatHamamDastShoie',
            'slug' => Str::slug('Tajhizat HamamDastShoie', '-')
        ]);
        $childCategory5 = [
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
        foreach ($childCategory5 as $childName5) {
            ActivityCategory::create([
                'name' => $childName5,
                'slug' => '',
                'parent_id' => $bathroomEquipments->id
            ]);
        }

        $kitchenEquipments = ActivityCategory::create([
            'name' => 'TajhizatAshpazKhane',
            'slug' => Str::slug('Tajhizat-AshpazKhane', '-')
        ]);
        $childCategory6 = [
            "تجهیزات آشپزخانه",
            "سینک ظرفشویی",
            "فر توکار",
            "هود",
            "گاز رومیزی",
            "کابینت آشپرخانه"
        ];
        foreach ($childCategory6 as $childName6) {
            ActivityCategory::create([
                'name' => $childName6,
                'slug' => '',
                'parent_id' => $kitchenEquipments->id
            ]);
        }

    }
}
