import fs from "node:fs/promises";
import path from "node:path";
import { pathToFileURL } from "node:url";
import { Presentation, PresentationFile } from "@oai/artifact-tool";

const { SKILL_DIR, TMP_DIR, OUTPUT_DIR, PROJECT_DIR, RUNTIME_PYTHON } = process.env;
for (const [name, value] of Object.entries({ SKILL_DIR, TMP_DIR, OUTPUT_DIR, PROJECT_DIR, RUNTIME_PYTHON })) {
  if (!path.isAbsolute(value ?? "")) throw new Error(`${name} must be absolute`);
}

const { finalizePresentation, resolvePresentationFont } = await import(
  pathToFileURL(path.join(SKILL_DIR, "container_tools/artifact_tool_utils.mjs")).href,
);

const font = resolvePresentationFont();
const W = 1280;
const H = 720;
const C = {
  navy: "#0D2230",
  navy2: "#173747",
  ink: "#142735",
  muted: "#5B6D78",
  green: "#2F956E",
  greenSoft: "#E9F5F0",
  cyan: "#2D8FAF",
  cyanSoft: "#E7F5FA",
  amber: "#C78A22",
  amberSoft: "#FFF4D9",
  blueSoft: "#E8F2FA",
  white: "#FFFFFF",
  paper: "#F4F8FA",
  line: "#D7E1E7",
};

await fs.mkdir(TMP_DIR, { recursive: true });
await fs.mkdir(OUTPUT_DIR, { recursive: true });

const assets = path.join(PROJECT_DIR, "assets", "readme");
const [catalogPng, sectionsPng, productsPng] = await Promise.all([
  fs.readFile(path.join(assets, "catalog-preview.png")),
  fs.readFile(path.join(assets, "nested-sections.png")),
  fs.readFile(path.join(assets, "imported-products.png")),
]);

const deck = Presentation.create({ slideSize: { width: W, height: H } });

function rect(slide, left, top, width, height, fill, radius = 0, line = "none") {
  return slide.shapes.add({
    geometry: radius ? "roundRect" : "rect",
    position: { left, top, width, height },
    fill,
    line: line === "none" ? { fill: "none", width: 0 } : { fill: line, width: 1 },
    ...(radius ? { borderRadius: radius } : {}),
  });
}

function text(slide, value, left, top, width, height, size = 28, color = C.ink, bold = false, align = "left") {
  const box = slide.shapes.add({
    geometry: "textbox",
    position: { left, top, width, height },
    fill: "none",
    line: { fill: "none", width: 0 },
  });
  box.text = value;
  box.text.style = {
    typeface: font,
    fontSize: size,
    bold,
    color,
    alignment: align,
    autoFit: "shrinkText",
  };
  return box;
}

function title(slide, heading, subtitle = "", dark = false) {
  text(slide, heading, 72, 44, 1136, 58, 34, dark ? C.white : C.ink, true);
  rect(slide, 72, 112, 72, 5, C.green, 2);
  if (subtitle) text(slide, subtitle, 72, 126, 1136, 44, 18, dark ? "#C7D6DE" : C.muted);
}

function footer(slide, index, dark = false) {
  text(slide, `WEBENOT · IMPORT FROM EXCEL`, 72, 680, 460, 20, 11, dark ? "#9DB2BE" : "#82939D", true);
  text(slide, String(index).padStart(2, "0"), 1160, 680, 48, 20, 11, dark ? "#9DB2BE" : "#82939D", true, "right");
}

function addImage(slide, blob, alt, left, top, width, height, fit = "cover", crop) {
  return slide.images.add({
    blob,
    contentType: "image/png",
    alt,
    fit,
    position: { left, top, width, height },
    geometry: "roundRect",
    borderRadius: 18,
    ...(crop ? { crop } : {}),
  });
}

function pill(slide, label, left, top, width, fill, color) {
  rect(slide, left, top, width, 36, fill, 18);
  text(slide, label, left + 12, top + 7, width - 24, 22, 13, color, true, "center");
}

function metric(slide, number, label, left, top, width, fill = C.paper) {
  rect(slide, left, top, width, 126, fill, 18);
  text(slide, number, left + 20, top + 18, width - 40, 54, 36, C.ink, true);
  text(slide, label, left + 20, top + 79, width - 40, 28, 15, C.muted);
}

// 1 — cover
{
  const s = deck.slides.add();
  s.background.fill = C.navy;
  rect(s, 870, -120, 520, 520, C.navy2, 260);
  rect(s, 980, 330, 260, 260, "#1D4C5E", 130);
  pill(s, "МОДУЛЬ ДЛЯ 1С-БИТРИКС", 72, 72, 245, "#1B4353", "#A8DBCA");
  text(s, "Импорт из Excel\nбез слепой записи", 72, 155, 720, 170, 54, C.white, true);
  text(s, "XLSX, XLS, ODS и CSV → разделы, свойства, тексты и изображения в новом или существующем инфоблоке.", 76, 350, 650, 90, 24, "#D4E0E6");
  rect(s, 76, 482, 474, 3, C.green, 2);
  text(s, "Сначала проверка. Потом — контролируемый импорт.", 76, 510, 620, 42, 20, C.white, true);
  metric(s, "194", "товара в сквозном тесте", 790, 182, 188, C.white);
  metric(s, "668", "файлов изображений", 996, 182, 188, C.white);
  metric(s, "0", "ошибок проверки", 790, 326, 394, C.greenSoft);
  footer(s, 1, true);
  s.speakerNotes.textFrame.setText("Проверенный демонстрационный импорт выполнен на открытом каталоге DummyJSON Products.");
}

// 2 — source workbook
{
  const s = deck.slides.add();
  s.background.fill = C.white;
  title(s, "От рабочей книги — к понятной структуре", "Модуль показывает листы и первые строки, а пользователь выбирает правильный заголовок.");
  addImage(s, catalogPng, "Каталог товаров в Excel", 72, 190, 760, 420, "cover", { left: 0, top: 0.03, right: 0.26, bottom: 0.08 });
  pill(s, "РЕАЛЬНАЯ ТЕСТОВАЯ КНИГА", 880, 192, 286, C.cyanSoft, C.cyan);
  text(s, "2 листа", 880, 252, 300, 46, 34, C.ink, true);
  text(s, "Каталог товаров\nКатегории", 880, 305, 300, 64, 19, C.muted);
  text(s, "28 колонок", 880, 406, 300, 46, 34, C.ink, true);
  text(s, "цены · остатки · размеры\nтексты · теги · изображения", 880, 459, 310, 68, 19, C.muted);
  rect(s, 880, 552, 304, 58, C.greenSoft, 14);
  text(s, "Нужный лист выбирается до импорта", 898, 568, 268, 28, 16, C.green, true);
  footer(s, 2);
  s.speakerNotes.textFrame.setText("Источник данных: DummyJSON Products API, https://dummyjson.com/docs/products");
}

// 3 — mapping
{
  const s = deck.slides.add();
  s.background.fill = C.paper;
  title(s, "Модуль помогает, но не угадывает", "Однозначные назначения распознаются автоматически. Всё спорное остаётся за пользователем.");
  const cards = [
    { x: 72, color: C.green, bg: C.greenSoft, label: "РАСПОЗНАНО", head: "Название", body: "→ Название элемента" },
    { x: 430, color: C.amber, bg: C.amberSoft, label: "НУЖНО ВЫБРАТЬ", head: "Внутренний код", body: "→ — выбрать значение —" },
    { x: 788, color: C.cyan, bg: C.blueSoft, label: "НАСТРОЕНО", head: "Артикул", body: "→ ARTIKUL · строка" },
  ];
  for (const card of cards) {
    rect(s, card.x, 198, 320, 184, C.white, 20, C.line);
    rect(s, card.x, 198, 320, 8, card.color, 4);
    pill(s, card.label, card.x + 22, 226, 190, card.bg, card.color);
    text(s, card.head, card.x + 24, 280, 272, 34, 23, C.ink, true);
    text(s, card.body, card.x + 24, 328, 272, 28, 17, C.muted);
  }
  rect(s, 72, 420, 1036, 148, C.white, 20, C.line);
  text(s, "Одна колонка — одно назначение", 100, 446, 400, 42, 26, C.ink, true);
  text(s, "Существующее свойство выбирается по имени и коду. Для нового свойства открывается отдельное окно с типом, множественностью, обязательностью и редактируемым символьным кодом.", 100, 500, 950, 54, 18, C.muted);
  footer(s, 3);
}

// 4 — dry-run
{
  const s = deck.slides.add();
  s.background.fill = C.white;
  title(s, "Сначала проверка, потом запись", "Контрольный запуск рассчитывает результат и объём восстановления, но не меняет каталог.");
  const labels = [
    ["194", "прочитано"],
    ["0", "будет добавлено"],
    ["194", "будет обновлено"],
    ["0", "ошибок"],
  ];
  labels.forEach(([n, l], i) => metric(s, n, l, 72 + i * 252, 202, 224, i === 3 ? C.greenSoft : C.paper));
  rect(s, 72, 366, 980, 170, C.navy, 22);
  text(s, "Откат — до нажатия «Импортировать»", 104, 394, 600, 38, 27, C.white, true);
  text(s, "29,5 МБ", 104, 458, 220, 50, 34, "#B6E4D4", true);
  text(s, "668 файлов для резервной копии", 325, 465, 380, 34, 19, "#D6E2E8");
  rect(s, 760, 404, 250, 78, C.green, 16);
  text(s, "Выполнить импорт", 784, 428, 202, 30, 18, C.white, true, "center");
  text(s, "Пользователь может сохранить откат или импортировать без него — решение принимается с понятной оценкой места.", 72, 566, 1050, 54, 18, C.muted);
  footer(s, 4);
}

// 5 — hierarchy
{
  const s = deck.slides.add();
  s.background.fill = C.paper;
  title(s, "Вложенность создаётся штатными разделами Битрикс", "Уровни таблицы превращаются в родительские и дочерние CIBlockSection.");
  addImage(s, sectionsPng, "Вложенные разделы в новом инфоблоке", 432, 184, 776, 436, "cover", { left: 0, top: 0.06, right: 0, bottom: 0.29 });
  metric(s, "6", "верхних разделов", 72, 200, 300, C.white);
  metric(s, "24", "дочерних раздела", 72, 344, 300, C.white);
  rect(s, 72, 488, 300, 132, C.greenSoft, 18);
  text(s, "30 разделов", 96, 510, 252, 40, 30, C.green, true);
  text(s, "и корректный путь\nдо самого глубокого уровня", 96, 556, 252, 46, 16, C.muted);
  footer(s, 5);
}

// 6 — result and images
{
  const s = deck.slides.add();
  s.background.fill = C.white;
  title(s, "Полный каталог создан в новом инфоблоке", "Товары, свойства, тексты и внешние изображения доступны штатной административной части.");
  addImage(s, productsPng, "Импортированные товары в Битрикс", 72, 184, 760, 428, "cover", { left: 0, top: 0.07, right: 0, bottom: 0.07 });
  metric(s, "194", "товара", 874, 184, 310, C.greenSoft);
  metric(s, "18", "свойств", 874, 326, 310, C.cyanSoft);
  rect(s, 874, 468, 310, 144, C.navy, 18);
  text(s, "668", 898, 490, 262, 46, 34, C.white, true);
  text(s, "изображений скачано\nи сохранено в Битрикс", 898, 544, 262, 48, 17, "#C8D8DF");
  footer(s, 6);
  s.speakerNotes.textFrame.setText("194 изображения анонса + 194 детальных изображения + 280 файлов галереи.");
}

// 7 — two destinations
{
  const s = deck.slides.add();
  s.background.fill = C.paper;
  title(s, "Один интерфейс для нового и существующего каталога", "Профиль хранит структуру и правила; рабочий файл можно менять при каждом запуске.");
  rect(s, 72, 196, 500, 326, C.white, 22, C.line);
  pill(s, "НОВЫЙ ИНФОБЛОК", 100, 224, 210, C.greenSoft, C.green);
  text(s, "Создать структуру\nс нуля", 100, 286, 390, 76, 34, C.ink, true);
  text(s, "• указать название и тип\n• создать свойства из колонок\n• построить вложенные разделы\n• заполнить URL-шаблоны", 100, 386, 390, 112, 18, C.muted);
  rect(s, 608, 196, 500, 326, C.white, 22, C.line);
  pill(s, "СУЩЕСТВУЮЩИЙ ИНФОБЛОК", 636, 224, 268, C.cyanSoft, C.cyan);
  text(s, "Обновить без\nдублирования", 636, 286, 390, 76, 34, C.ink, true);
  text(s, "• выбрать реальные свойства\n• найти элементы по артикулу или ID\n• обновлять только назначенные поля\n• не менять существующие URL-шаблоны", 636, 386, 420, 112, 18, C.muted);
  rect(s, 72, 558, 1036, 56, C.navy, 16);
  text(s, "Режимы записи: добавить · обновить · добавить и обновить", 104, 575, 972, 26, 18, C.white, true, "center");
  footer(s, 7);
}

// 8 — close
{
  const s = deck.slides.add();
  s.background.fill = C.navy;
  rect(s, 890, -150, 520, 520, C.navy2, 260);
  pill(s, "ГОТОВО К УСТАНОВКЕ", 72, 82, 220, "#1B4353", "#A8DBCA");
  text(s, "Каталог меняется.\nКонтроль остаётся.", 72, 166, 760, 136, 50, C.white, true);
  text(s, "Импорт из Excel для 1С-Битрикс", 76, 334, 680, 42, 24, "#D6E2E8");
  rect(s, 76, 412, 510, 3, C.green, 2);
  text(s, "github.com/YuryGorshkov/IMPORT_FROM_EXCEL", 76, 444, 700, 34, 20, C.white, true);
  text(s, "Профили · проверка · изображения · разделы · история · откат", 76, 502, 830, 36, 18, "#A9BDC7");
  rect(s, 840, 212, 344, 256, C.white, 24);
  text(s, "194", 876, 248, 272, 66, 48, C.ink, true, "center");
  text(s, "товара", 876, 314, 272, 32, 20, C.muted, true, "center");
  text(s, "30 разделов · 18 свойств\n668 изображений · 0 ошибок", 876, 372, 272, 70, 18, C.green, true, "center");
  footer(s, 8, true);
}

const outputPath = path.join(OUTPUT_DIR, "IMPORT_FROM_EXCEL_Presentation_final.pptx");
const stagingDir = path.join(TMP_DIR, ".codex-finalizer");
const candidatePath = path.join(stagingDir, "candidate.pptx");
await fs.mkdir(stagingDir, { recursive: true });
await (await PresentationFile.exportPptx(deck)).save(candidatePath);

await finalizePresentation({
  workspaceDir: PROJECT_DIR,
  candidatePath,
  finalPath: outputPath,
  pythonExecutable: RUNTIME_PYTHON,
  integrityValidatorPath: path.join(SKILL_DIR, "container_tools/inspect_presentation_package_integrity.py"),
  layoutValidatorPath: path.join(SKILL_DIR, "container_tools/inspect_presentation_layout_geometry.py"),
  layoutArgs: [
    "--expected-slide-size-emu", "12192000,6858000",
    "--validate-bullet-geometry",
    "--validate-heading-fit",
  ],
  explicitTotalSlideCount: 8,
  requiredNativeTableOwnerSlides: [],
  requiredNativeChartOwnerSlides: [],
  fontPolicy: { basis: "design", families: [font] },
  verifyArtifactToolImport: true,
  receiptPath: path.join(stagingDir, "IMPORT_FROM_EXCEL_Presentation.validation.json"),
});

for (let i = 0; i < deck.slides.items.length; i += 1) {
  const slide = deck.slides.items[i];
  const preview = await deck.export({ slide, format: "png", scale: 1 });
  await fs.writeFile(path.join(TMP_DIR, `slide-${i + 1}.png`), new Uint8Array(await preview.arrayBuffer()));
  const layout = await slide.export({ format: "layout" });
  await fs.writeFile(path.join(TMP_DIR, `slide-${i + 1}.layout.json`), await layout.text());
}

const inspection = await deck.inspect({ kind: "slide,textbox,shape,image,notes,layout", maxChars: 30000 });
await fs.writeFile(path.join(TMP_DIR, "inspection.ndjson"), inspection.ndjson, "utf8");
console.log(JSON.stringify({ outputPath, slides: deck.slides.items.length }));
