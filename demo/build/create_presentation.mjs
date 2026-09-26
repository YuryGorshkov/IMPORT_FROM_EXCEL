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
const [catalogPng, mappingPng, dryRunPng, sectionsPng, productsPng] = await Promise.all([
  fs.readFile(path.join(assets, "catalog-preview.png")),
  fs.readFile(path.join(assets, "mapping-profile.png")),
  fs.readFile(path.join(assets, "dry-run.png")),
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
  text(slide, `WEBENOT / IMPORT FROM EXCEL`, 72, 680, 460, 20, 11, dark ? "#9DB2BE" : "#82939D", true);
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
  text(s, "Файлы XLSX, XLS, ODS и CSV превращаются в разделы, свойства, тексты и изображения Битрикс.", 76, 350, 650, 90, 24, "#D4E0E6");
  rect(s, 76, 482, 474, 3, C.green, 2);
  text(s, "Сначала проверка, затем контролируемый импорт.", 76, 510, 620, 42, 20, C.white, true);
  rect(s, 790, 182, 394, 112, C.white, 18);
  text(s, "Профили импорта", 820, 210, 334, 34, 28, C.ink, true);
  text(s, "Настройка один раз, рабочий файл при каждом запуске", 820, 250, 334, 28, 15, C.muted);
  rect(s, 790, 316, 394, 112, C.greenSoft, 18);
  text(s, "Проверка без записи", 820, 344, 334, 34, 28, C.ink, true);
  text(s, "Результат и оценка отката до изменения каталога", 820, 384, 334, 28, 15, C.muted);
  rect(s, 790, 450, 394, 112, C.cyanSoft, 18);
  text(s, "Новый или существующий", 820, 478, 334, 34, 28, C.ink, true);
  text(s, "Один интерфейс для обоих сценариев", 820, 518, 334, 28, 15, C.muted);
  footer(s, 1, true);
}

// 2 — source workbook
{
  const s = deck.slides.add();
  s.background.fill = C.white;
  title(s, "Рабочая книга и выбор листа", "Модуль показывает структуру файла и первые строки до настройки импорта.");
  addImage(s, catalogPng, "Каталог товаров в Excel", 72, 190, 760, 420, "cover", { left: 0, top: 0.03, right: 0.26, bottom: 0.08 });
  pill(s, "РАБОЧИЙ ФАЙЛ", 880, 192, 286, C.cyanSoft, C.cyan);
  text(s, "Несколько листов", 880, 252, 300, 46, 32, C.ink, true);
  text(s, "Пользователь выбирает нужный лист перед проверкой.", 880, 305, 300, 64, 18, C.muted);
  text(s, "Своя структура", 880, 406, 300, 46, 32, C.ink, true);
  text(s, "Строка заголовков определяет назначение каждого столбца.", 880, 459, 310, 68, 18, C.muted);
  rect(s, 880, 552, 304, 58, C.greenSoft, 14);
  text(s, "На этом шаге каталог не меняется", 898, 568, 268, 28, 16, C.green, true);
  footer(s, 2);
}

// 3 — mapping
{
  const s = deck.slides.add();
  s.background.fill = C.paper;
  title(s, "Сопоставление колонок", "Однозначные назначения модуль предлагает автоматически. Остальные выбирает пользователь.");
  addImage(s, mappingPng, "Сопоставление колонок в профиле импорта", 72, 188, 798, 432, "cover", { left: 0.02, top: 0.16, right: 0.02, bottom: 0.09 });
  pill(s, "РАСПОЗНАНО", 910, 206, 220, C.greenSoft, C.green);
  text(s, "Точное соответствие", 910, 254, 260, 34, 22, C.ink, true);
  text(s, "Назначение можно проверить и изменить.", 910, 294, 270, 44, 17, C.muted);
  pill(s, "НУЖНО ВЫБРАТЬ", 910, 362, 220, C.amberSoft, C.amber);
  text(s, "Решение пользователя", 910, 410, 270, 34, 22, C.ink, true);
  text(s, "Можно выбрать поле, свойство или отключить колонку.", 910, 450, 270, 52, 17, C.muted);
  pill(s, "НАСТРОЕНО", 910, 526, 220, C.blueSoft, C.cyan);
  text(s, "Профиль сохраняет выбор", 910, 574, 270, 34, 20, C.ink, true);
  footer(s, 3);
}

// 4 — dry-run
{
  const s = deck.slides.add();
  s.background.fill = C.white;
  title(s, "Проверка перед импортом", "Контрольный запуск рассчитывает изменения и не записывает данные в каталог.");
  addImage(s, dryRunPng, "Результат проверки файла без записи", 72, 188, 790, 432, "cover", { left: 0.02, top: 0.08, right: 0.02, bottom: 0.14 });
  text(s, "Расчёт результата", 910, 206, 270, 38, 26, C.ink, true);
  text(s, "Видно, сколько строк модуль добавит, обновит или пропустит.", 910, 254, 270, 78, 18, C.muted);
  text(s, "Оценка отката", 910, 362, 270, 38, 26, C.ink, true);
  text(s, "До запуска отображаются объём резервной копии и количество файлов.", 910, 410, 270, 78, 18, C.muted);
  rect(s, 910, 524, 270, 72, C.greenSoft, 16);
  text(s, "Запись запускается отдельной кнопкой", 932, 544, 226, 38, 17, C.green, true, "center");
  footer(s, 4);
}

// 5 — hierarchy
{
  const s = deck.slides.add();
  s.background.fill = C.paper;
  title(s, "Вложенные разделы", "Каждый уровень из файла становится обычным разделом Битрикс в общей иерархии.");
  addImage(s, sectionsPng, "Вложенные разделы в новом инфоблоке", 432, 184, 776, 436, "cover", { left: 0, top: 0.06, right: 0, bottom: 0.29 });
  text(s, "Уровень 1", 72, 216, 300, 40, 30, C.ink, true);
  text(s, "Родительский раздел каталога", 72, 264, 300, 34, 18, C.muted);
  text(s, "Уровень 2 и ниже", 72, 350, 300, 40, 30, C.ink, true);
  text(s, "Дочерние разделы внутри выбранного пути", 72, 398, 300, 54, 18, C.muted);
  rect(s, 72, 504, 300, 116, C.greenSoft, 18);
  text(s, "Путь формируется сверху вниз", 96, 534, 252, 54, 24, C.green, true);
  footer(s, 5);
}

// 6 — result and images
{
  const s = deck.slides.add();
  s.background.fill = C.white;
  title(s, "Результат в инфоблоке", "Импортированные данные остаются доступны штатным компонентам и административным инструментам Битрикс.");
  addImage(s, productsPng, "Импортированные товары в Битрикс", 72, 184, 760, 428, "cover", { left: 0, top: 0.07, right: 0, bottom: 0.07 });
  text(s, "Поля элемента", 874, 194, 310, 36, 27, C.ink, true);
  text(s, "Название, коды, активность, тексты и даты.", 874, 240, 310, 56, 18, C.muted);
  text(s, "Свойства инфоблока", 874, 330, 310, 36, 27, C.ink, true);
  text(s, "Существующие свойства обновляются, новые создаются по настройкам профиля.", 874, 376, 310, 76, 18, C.muted);
  rect(s, 874, 486, 310, 126, C.navy, 18);
  text(s, "Файлы и изображения", 898, 510, 262, 36, 25, C.white, true);
  text(s, "Ссылки скачиваются и сохраняются средствами Битрикс.", 898, 554, 262, 44, 17, "#C8D8DF");
  footer(s, 6);
}

// 7 — two destinations
{
  const s = deck.slides.add();
  s.background.fill = C.paper;
  title(s, "Новый и существующий инфоблок", "Профиль хранит структуру и правила. Рабочий файл можно менять при каждом запуске.");
  rect(s, 72, 196, 500, 326, C.white, 22, C.line);
  pill(s, "НОВЫЙ ИНФОБЛОК", 100, 224, 210, C.greenSoft, C.green);
  text(s, "Создать структуру\nс нуля", 100, 286, 390, 76, 34, C.ink, true);
  text(s, "• указать название и тип\n• создать свойства из колонок\n• построить вложенные разделы\n• заполнить URL-шаблоны", 100, 386, 390, 112, 18, C.muted);
  rect(s, 608, 196, 500, 326, C.white, 22, C.line);
  pill(s, "СУЩЕСТВУЮЩИЙ ИНФОБЛОК", 636, 224, 268, C.cyanSoft, C.cyan);
  text(s, "Обновить без\nдублирования", 636, 286, 390, 76, 34, C.ink, true);
  text(s, "• выбрать реальные свойства\n• найти элементы по артикулу или ID\n• обновлять только назначенные поля\n• не менять существующие URL-шаблоны", 636, 386, 420, 112, 18, C.muted);
  rect(s, 72, 558, 1036, 56, C.navy, 16);
  text(s, "Режимы записи: добавить, обновить, добавить и обновить", 104, 575, 972, 26, 18, C.white, true, "center");
  footer(s, 7);
}

// 8 — close
{
  const s = deck.slides.add();
  s.background.fill = C.navy;
  rect(s, 890, -150, 520, 520, C.navy2, 260);
  pill(s, "ГОТОВО К УСТАНОВКЕ", 72, 82, 220, "#1B4353", "#A8DBCA");
  text(s, "Импорт из Excel\nдля 1С-Битрикс", 72, 166, 760, 136, 50, C.white, true);
  text(s, "Контролируемая загрузка в новый или существующий инфоблок", 76, 334, 680, 62, 24, "#D6E2E8");
  rect(s, 76, 412, 510, 3, C.green, 2);
  text(s, "github.com/YuryGorshkov/IMPORT_FROM_EXCEL", 76, 444, 700, 34, 20, C.white, true);
  text(s, "Профили, проверка, изображения, разделы, история и откат", 76, 502, 830, 36, 18, "#A9BDC7");
  rect(s, 840, 212, 344, 256, C.white, 24);
  text(s, "XLSX  XLS\nODS  CSV", 876, 254, 272, 100, 38, C.ink, true, "center");
  text(s, "Разделы и свойства\nТексты и изображения\nПроверка и откат", 876, 372, 272, 84, 18, C.green, true, "center");
  footer(s, 8, true);
}

const outputPath = path.join(OUTPUT_DIR, "IMPORT_FROM_EXCEL_Presentation_universal.pptx");
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
  receiptPath: path.join(stagingDir, "IMPORT_FROM_EXCEL_Presentation_universal.validation.json"),
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
