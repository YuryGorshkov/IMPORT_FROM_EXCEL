<?php

declare(strict_types=1);

namespace WebEnot\ImportExcel\Admin;

final class AdminUi
{
    private static bool $stylesRendered = false;

    public static function renderStyles(): void
    {
        if (self::$stylesRendered) {
            return;
        }

        self::$stylesRendered = true;
        echo <<<'HTML'
<style>
.wie-page{max-width:1240px;margin:0 0 48px;color:#263238;font-size:14px}.wie-page *{box-sizing:border-box}.wie-intro{max-width:930px;margin:0 0 18px;color:#53646d;font-size:15px;line-height:1.55}.wie-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0 0 18px}.wie-step{display:flex;gap:12px;align-items:flex-start;padding:14px 16px;border:1px solid #d7e0e4;border-radius:9px;background:#f8fafb}.wie-step-number{display:flex;flex:0 0 30px;align-items:center;justify-content:center;width:30px;height:30px;border-radius:50%;background:#315b72;font-weight:bold}.wie-step .wie-step-number{display:flex;color:#fff;font-size:13px}.wie-step strong{display:block;margin:1px 0 3px}.wie-step span{display:block;color:#687780;font-size:12px;line-height:1.4}.wie-section{margin-top:16px;padding:20px;border:1px solid #d7e0e4;border-radius:10px;background:#fff;box-shadow:0 2px 7px rgba(0,0,0,.04)}.wie-section h2{display:flex;align-items:center;gap:6px;margin:0 0 5px;font-size:20px;line-height:1.3}.wie-section-number{color:#315b72}.wie-section-hint{max-width:920px;margin:0 0 18px;color:#687780;line-height:1.5}.wie-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px 24px}.wie-field{min-width:0}.wie-field-wide{grid-column:1/-1}.wie-label{display:flex;align-items:center;gap:5px;min-height:22px;margin:0 0 7px;font-size:14px;line-height:1.3;font-weight:600}.wie-label .adm-hint,.wie-check-text .adm-hint,.wie-title-with-hint .adm-hint{flex:0 0 auto;margin:0}.wie-page input:not([type]),.wie-page input[type=text],.wie-page input[type=number],.wie-page input[type=file],.wie-page select,.wie-page textarea{width:100%;min-height:38px;padding:7px 10px;font-size:14px}.wie-page input[type=file]{height:auto;padding:6px;background:#fff}.wie-page textarea{min-height:92px;resize:vertical}.wie-check{display:flex;gap:9px;align-items:flex-start;min-height:38px;padding-top:8px}.wie-check input{flex:0 0 auto;margin:2px 0 0}.wie-check-text{display:flex;align-items:center;gap:5px;font-weight:600}.wie-field-note{margin-top:6px;color:#718089;font-size:12px;line-height:1.45}.wie-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:20px}.wie-primary,.wie-secondary{display:inline-flex;align-items:center;justify-content:center;min-height:38px;padding:0 18px;border-radius:5px;font-weight:bold;cursor:pointer;text-decoration:none}.wie-primary{border:0;background:#315b72;color:#fff}.wie-primary:hover{background:#274b5f;color:#fff}.wie-secondary{border:1px solid #9fb0b9;background:#fff;color:#315b72}.wie-secondary:hover{border-color:#6f8793;color:#203f4f}.wie-discovery{padding:16px;border:1px dashed #aebdc5;border-radius:8px;background:#f8fbfc}.wie-mapping-wrap{overflow:auto;border:1px solid #dce3e7;border-radius:8px}.wie-mapping{width:100%;min-width:920px;border-collapse:collapse;background:#fff}.wie-mapping th,.wie-mapping td{padding:10px;border-bottom:1px solid #e4eaed;text-align:left;vertical-align:middle}.wie-mapping tr:last-child td{border-bottom:0}.wie-mapping th{background:#f1f5f7;color:#40545e;font-size:12px;font-weight:600;text-transform:uppercase}.wie-mapping .wie-col-use{width:78px;text-align:center}.wie-mapping .wie-col-source{width:70px}.wie-mapping .wie-col-required{width:110px;text-align:center}.wie-mapping input,.wie-mapping select{min-height:34px}.wie-code-control{display:grid;grid-template-columns:145px minmax(210px,1fr);gap:8px}.wie-empty{padding:26px;border:1px dashed #b7c4ca;border-radius:8px;background:#f8fafb;text-align:center}.wie-empty strong{display:block;margin-bottom:6px;font-size:16px}.wie-empty span{color:#687780;line-height:1.45}.wie-safe{display:flex;gap:12px;align-items:flex-start;margin-top:16px;padding:14px 16px;border:1px solid #c9dfc5;border-radius:8px;background:#f2faef;color:#395336}.wie-safe-icon{font-size:20px;line-height:1}.wie-safe strong{display:block;margin-bottom:3px}.wie-kpis{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:12px;margin-top:16px}.wie-kpi{padding:16px;border:1px solid #d7e0e4;border-radius:9px;background:#fff}.wie-kpi span{display:block;margin-bottom:8px;color:#687780;font-size:12px;text-transform:uppercase}.wie-kpi strong{font-size:27px;color:#315b72}.wie-kpi-error strong{color:#b5483e}.wie-badge{display:inline-flex;align-items:center;min-height:25px;padding:2px 9px;border-radius:999px;background:#edf3f6;color:#40545e;font-size:12px;font-weight:600}.wie-badge-success{background:#e7f5e5;color:#397337}.wie-badge-warning{background:#fff4d6;color:#88651e}.wie-badge-danger{background:#fbe7e5;color:#a13e35}.wie-badge-info{background:#e4f2f8;color:#2f6984}.wie-list-intro{max-width:1240px;margin:0 0 16px;padding:16px 18px;border:1px solid #d7e0e4;border-radius:9px;background:#fff;color:#53646d;line-height:1.5;box-shadow:0 2px 7px rgba(0,0,0,.04)}.wie-list-intro strong{display:block;margin-bottom:3px;color:#263238;font-size:16px}.wie-title-with-hint{display:flex;align-items:center;gap:5px}.wie-tech{margin-top:16px}.wie-tech summary{cursor:pointer;color:#53646d;font-weight:600}.wie-tech-content{margin-top:12px;padding:14px;border-radius:7px;background:#f5f7f8;color:#53646d}.wie-inline-warning{margin:14px 0;padding:14px 16px;border-left:4px solid #e0a928;background:#fff8e4;line-height:1.5}@media(max-width:950px){.wie-steps,.wie-grid{grid-template-columns:1fr}.wie-field-wide{grid-column:auto}.wie-kpis{grid-template-columns:repeat(2,minmax(120px,1fr))}}@media(max-width:600px){.wie-kpis{grid-template-columns:1fr}.wie-section{padding:15px}.wie-actions{align-items:stretch}.wie-primary,.wie-secondary{width:100%}}
</style>
HTML;
    }

    public static function badge(string $label, string $tone = ''): string
    {
        $allowedTones = ['success', 'warning', 'danger', 'info'];
        $modifier = in_array($tone, $allowedTones, true) ? ' wie-badge-' . $tone : '';

        return '<span class="wie-badge' . $modifier . '">'
            . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</span>';
    }
}
