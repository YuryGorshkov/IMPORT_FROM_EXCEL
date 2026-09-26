import fs from "node:fs/promises";
import path from "node:path";
import { SpreadsheetFile, Workbook } from "@oai/artifact-tool";

const repoRoot = path.resolve(import.meta.dirname, "../..");
const sourcePath = path.join(repoRoot, "demo/source/dummyjson-products.json");
const categoriesPath = path.join(repoRoot, "demo/source/dummyjson-categories.json");
const outputDir = path.join(repoRoot, "demo");
const previewDir = path.join(repoRoot, "demo/build/previews");
const outputPath = path.join(outputDir, "dummyjson_catalog_194.xlsx");

const source = JSON.parse(await fs.readFile(sourcePath, "utf8"));
const categorySource = JSON.parse(await fs.readFile(categoriesPath, "utf8"));

const categoryMap = {
  beauty: ["Красота и уход", "Косметика"],
  fragrances: ["Красота и уход", "Парфюмерия"],
  "skin-care": ["Красота и уход", "Уход за кожей"],
  furniture: ["Дом и интерьер", "Мебель"],
  "home-decoration": ["Дом и интерьер", "Декор"],
  "kitchen-accessories": ["Дом и интерьер", "Кухонные принадлежности"],
  groceries: ["Дом и интерьер", "Продукты"],
  laptops: ["Электроника", "Ноутбуки"],
  "mobile-accessories": ["Электроника", "Мобильные аксессуары"],
  smartphones: ["Электроника", "Смартфоны"],
  tablets: ["Электроника", "Планшеты"],
  "mens-shirts": ["Мужская мода", "Рубашки"],
  "mens-shoes": ["Мужская мода", "Обувь"],
  "mens-watches": ["Мужская мода", "Часы"],
  sunglasses: ["Мужская мода", "Солнцезащитные очки"],
  tops: ["Женская мода", "Топы"],
  "womens-bags": ["Женская мода", "Сумки"],
  "womens-dresses": ["Женская мода", "Платья"],
  "womens-jewellery": ["Женская мода", "Украшения"],
  "womens-shoes": ["Женская мода", "Обувь"],
  "womens-watches": ["Женская мода", "Часы"],
  motorcycle: ["Спорт и транспорт", "Мотоциклы"],
  "sports-accessories": ["Спорт и транспорт", "Спортивные товары"],
  vehicle: ["Спорт и транспорт", "Автомобили"],
};

const products = [...source.products].sort((a, b) => {
  const [groupA, categoryA] = categoryMap[a.category] ?? ["Прочее", a.category];
  const [groupB, categoryB] = categoryMap[b.category] ?? ["Прочее", b.category];
  return groupA.localeCompare(groupB, "ru") || categoryA.localeCompare(categoryB, "ru") || a.title.localeCompare(b.title, "en");
});

const headers = [
  "ID источника",
  "Артикул",
  "Название",
  "Активность",
  "Раздел 1-го уровня",
  "Раздел 2-го уровня",
  "Бренд",
  "Цена, USD",
  "Скидка, %",
  "Остаток",
  "Рейтинг",
  "Вес",
  "Ширина",
  "Высота",
  "Глубина",
  "Текст анонса",
  "Детальное описание",
  "Картинка анонса",
  "Детальная картинка",
  "Галерея изображений",
  "Теги",
  "Статус наличия",
  "Гарантия",
  "Доставка",
  "Возврат",
  "Минимальный заказ",
  "Штрихкод",
  "Обновлено",
];

function excerpt(value, maxLength = 150) {
  const text = String(value ?? "").trim();
  return text.length <= maxLength ? text : `${text.slice(0, maxLength - 1).trim()}…`;
}

const rows = products.map((product) => {
  const [group, category] = categoryMap[product.category] ?? ["Прочее", product.category];
  const primaryImage = product.images?.[0] ?? product.thumbnail ?? "";
  const gallery = (product.images ?? []).slice(1).join("\n");
  return [
    String(product.id),
    String(product.sku ?? ""),
    String(product.title ?? ""),
    "Да",
    group,
    category,
    String(product.brand ?? "Без бренда"),
    Number(product.price ?? 0),
    Number(product.discountPercentage ?? 0) / 100,
    Number(product.stock ?? 0),
    Number(product.rating ?? 0),
    Number(product.weight ?? 0),
    Number(product.dimensions?.width ?? 0),
    Number(product.dimensions?.height ?? 0),
    Number(product.dimensions?.depth ?? 0),
    excerpt(product.description),
    String(product.description ?? ""),
    String(product.thumbnail ?? primaryImage),
    String(primaryImage),
    gallery,
    (product.tags ?? []).join(", "),
    String(product.availabilityStatus ?? ""),
    String(product.warrantyInformation ?? ""),
    String(product.shippingInformation ?? ""),
    String(product.returnPolicy ?? ""),
    Number(product.minimumOrderQuantity ?? 0),
    String(product.meta?.barcode ?? ""),
    product.meta?.updatedAt ? new Date(product.meta.updatedAt) : null,
  ];
});

const statsByCategory = new Map();
for (const product of products) {
  const [group, category] = categoryMap[product.category] ?? ["Прочее", product.category];
  const item = statsByCategory.get(product.category) ?? {
    group,
    category,
    slug: product.category,
    count: 0,
    minPrice: Number.POSITIVE_INFINITY,
    maxPrice: Number.NEGATIVE_INFINITY,
  };
  item.count += 1;
  item.minPrice = Math.min(item.minPrice, Number(product.price ?? 0));
  item.maxPrice = Math.max(item.maxPrice, Number(product.price ?? 0));
  statsByCategory.set(product.category, item);
}

const categoryUrlBySlug = new Map(categorySource.map((item) => [item.slug, item.url]));
const categoryRows = [...statsByCategory.values()]
  .sort((a, b) => a.group.localeCompare(b.group, "ru") || a.category.localeCompare(b.category, "ru"))
  .map((item) => [
    item.group,
    item.category,
    item.slug,
    item.count,
    item.minPrice,
    item.maxPrice,
    categoryUrlBySlug.get(item.slug) ?? `https://dummyjson.com/products/category/${item.slug}`,
  ]);

const workbook = Workbook.create();
const catalog = workbook.worksheets.add("Каталог товаров");
const categories = workbook.worksheets.add("Категории");

catalog.showGridLines = false;
catalog.freezePanes.freezeRows(4);
catalog.freezePanes.freezeColumns(3);
catalog.tabColor = "#256E8B";

catalog.getRange("A1:AB1").merge();
catalog.getRange("A1").values = [[`Демонстрационный каталог DummyJSON — ${products.length} товаров`]];
catalog.getRange("A1").format = {
  font: { name: "Arial", size: 15, bold: true, color: "#173C4B" },
  rowHeight: 28,
  verticalAlignment: "center",
};
catalog.getRange("A2:AB2").merge();
catalog.getRange("A2").values = [["Источник: https://dummyjson.com/products?limit=0 — публичные демонстрационные данные для тестирования интернет-магазинов"]];
catalog.getRange("A2").format = {
  font: { name: "Arial", size: 9, italic: true, color: "#5D7180" },
  rowHeight: 22,
  verticalAlignment: "center",
};
catalog.getRange("A4:AB4").values = [headers];
catalog.getRange(`A5:AB${rows.length + 4}`).values = rows;
catalog.getRange(`A4:AB${rows.length + 4}`).format.font = { name: "Arial", size: 10, color: "#1D2B36" };
catalog.getRange("A4:AB4").format = {
  fill: "#245D74",
  font: { name: "Arial", size: 10, bold: true, color: "#FFFFFF" },
  horizontalAlignment: "center",
  verticalAlignment: "center",
  wrapText: true,
  rowHeight: 34,
  borders: { preset: "inside", style: "thin", color: "#FFFFFF" },
};
catalog.getRange(`A5:AB${rows.length + 4}`).format.verticalAlignment = "top";
catalog.getRange(`A5:AB${rows.length + 4}`).format.borders = {
  insideHorizontal: { style: "thin", color: "#DDE7EC" },
};
catalog.getRange(`H5:H${rows.length + 4}`).format.numberFormat = "$#,##0.00";
catalog.getRange(`I5:I${rows.length + 4}`).format.numberFormat = "0.00%";
catalog.getRange(`J5:J${rows.length + 4}`).format.numberFormat = "#,##0";
catalog.getRange(`K5:O${rows.length + 4}`).format.numberFormat = "0.00";
catalog.getRange(`Z5:Z${rows.length + 4}`).format.numberFormat = "#,##0";
catalog.getRange(`AB5:AB${rows.length + 4}`).format.numberFormat = "yyyy-mm-dd";
catalog.getRange(`P5:Q${rows.length + 4}`).format.wrapText = true;
catalog.getRange(`T5:T${rows.length + 4}`).format.wrapText = true;
catalog.getRange(`P5:Q${rows.length + 4}`).format.rowHeight = 48;
catalog.getRange(`T5:T${rows.length + 4}`).format.rowHeight = 48;

const catalogWidths = [12, 18, 32, 11, 24, 27, 20, 13, 12, 11, 11, 10, 10, 10, 10, 38, 48, 42, 42, 52, 24, 18, 22, 26, 24, 18, 18, 14];
for (let index = 0; index < catalogWidths.length; index += 1) {
  catalog.getRangeByIndexes(0, index, rows.length + 4, 1).format.columnWidth = catalogWidths[index];
}
const catalogTable = catalog.tables.add(`A4:AB${rows.length + 4}`, true, "DemoCatalogTable");
catalogTable.style = "TableStyleMedium2";
catalogTable.showFilterButton = true;
catalogTable.showBandedColumns = false;

categories.showGridLines = false;
categories.freezePanes.freezeRows(4);
categories.tabColor = "#86A8B8";
categories.getRange("A1:G1").merge();
categories.getRange("A1").values = [["Справочник категорий демонстрационного каталога"]];
categories.getRange("A1").format = {
  font: { name: "Arial", size: 15, bold: true, color: "#173C4B" },
  rowHeight: 28,
  verticalAlignment: "center",
};
categories.getRange("A2:G2").merge();
categories.getRange("A2").values = [["Первый уровень добавлен для проверки вложенных разделов в 1С-Битрикс; второй уровень соответствует категориям источника DummyJSON."]];
categories.getRange("A2").format = {
  font: { name: "Arial", size: 9, italic: true, color: "#5D7180" },
  rowHeight: 22,
  verticalAlignment: "center",
};
const categoryHeaders = ["Раздел 1-го уровня", "Раздел 2-го уровня", "Код источника", "Товаров", "Цена от, USD", "Цена до, USD", "API категории"];
categories.getRange("A4:G4").values = [categoryHeaders];
categories.getRange(`A5:G${categoryRows.length + 4}`).values = categoryRows;
categories.getRange(`A4:G${categoryRows.length + 4}`).format.font = { name: "Arial", size: 10, color: "#1D2B36" };
categories.getRange("A4:G4").format = {
  fill: "#245D74",
  font: { name: "Arial", size: 10, bold: true, color: "#FFFFFF" },
  horizontalAlignment: "center",
  verticalAlignment: "center",
  wrapText: true,
  rowHeight: 34,
  borders: { preset: "inside", style: "thin", color: "#FFFFFF" },
};
categories.getRange(`A5:G${categoryRows.length + 4}`).format.borders = {
  insideHorizontal: { style: "thin", color: "#DDE7EC" },
};
categories.getRange(`D5:D${categoryRows.length + 4}`).format.numberFormat = "#,##0";
categories.getRange(`E5:F${categoryRows.length + 4}`).format.numberFormat = "$#,##0.00";
const categoryWidths = [24, 29, 24, 11, 16, 16, 52];
for (let index = 0; index < categoryWidths.length; index += 1) {
  categories.getRangeByIndexes(0, index, categoryRows.length + 4, 1).format.columnWidth = categoryWidths[index];
}
const categoryTable = categories.tables.add(`A4:G${categoryRows.length + 4}`, true, "DemoCategoryTable");
categoryTable.style = "TableStyleMedium2";
categoryTable.showFilterButton = true;

workbook.recalculate();
await fs.mkdir(outputDir, { recursive: true });
await fs.mkdir(previewDir, { recursive: true });

const catalogPreview = await workbook.render({ sheetName: "Каталог товаров", range: "A1:J18", scale: 1.2, format: "png" });
await fs.writeFile(path.join(previewDir, "catalog-preview.png"), new Uint8Array(await catalogPreview.arrayBuffer()));
const categoriesPreview = await workbook.render({ sheetName: "Категории", range: `A1:G${categoryRows.length + 4}`, scale: 1.2, format: "png" });
await fs.writeFile(path.join(previewDir, "categories-preview.png"), new Uint8Array(await categoriesPreview.arrayBuffer()));

const inspection = await workbook.inspect({
  kind: "table",
  range: "Каталог товаров!A1:J10",
  include: "values,formulas",
  tableMaxRows: 10,
  tableMaxCols: 10,
});
await fs.writeFile(path.join(previewDir, "inspection.ndjson"), inspection.ndjson, "utf8");

const errors = await workbook.inspect({
  kind: "match",
  searchTerm: "#REF!|#DIV/0!|#VALUE!|#NAME\\?|#N/A|#NUM!|#NULL!|#SPILL!|#CALC!",
  options: { useRegex: true, maxResults: 100 },
  summary: "final formula error scan",
});
await fs.writeFile(path.join(previewDir, "formula-errors.ndjson"), errors.ndjson, "utf8");

const output = await SpreadsheetFile.exportXlsx(workbook);
await output.save(outputPath);

console.log(JSON.stringify({ outputPath, products: products.length, categories: categoryRows.length, sheets: 2 }, null, 2));
