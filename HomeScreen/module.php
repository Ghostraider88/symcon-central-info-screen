<?php

declare(strict_types=1);

class HomeScreen extends IPSModuleStrict
{
    private const MODULE_VERSION = '1.0.3';
    private const UPDATE_DEBOUNCE_MS = 250;
    private const TREND_PRIMARY_SECONDS = 2 * 3600;
    private const TREND_FALLBACK_SECONDS = 6 * 3600;
    private const TREND_PRIMARY_THRESHOLD = 0.8;
    private const TREND_FALLBACK_THRESHOLD = 2.0;
    private const TREND_CACHE_TTL_SECONDS = 300;
    private const BODY_PADDING_WITH_TITLE = '35px';
    private const BODY_PADDING_WITHOUT_TITLE = '16px';

    private array $trendCache = [];
    private array $configurationErrors = [];
    private array $jsonListCache = [];
    private array $renderValueCache = [];
    private array $renderFormattedValueCache = [];
    private array $renderVariableInfoCache = [];
    private array $renderVariableExistsCache = [];
    private array $pendingUpdateIDs = [];
    private bool $configurationValidated = false;
    private ?int $archiveID = null;

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Bereiche',         '[]');
        $this->RegisterPropertyString('Raeume',           '[]');
        $this->RegisterPropertyString('Fahrzeuge',        '[]');
        $this->RegisterPropertyString('EnergieKacheln',   '[]');
        $this->RegisterPropertyString('KlimaGeraete',     '[]');
        $this->RegisterPropertyString('Bewaesserung',     '[]');
        $this->RegisterPropertyString('Lueftungsanlagen', '[]');
        $this->RegisterPropertyString('Waermepumpen',     '[]');

        $this->RegisterPropertyInteger('AussenTempID',    0);
        $this->RegisterPropertyInteger('AussenTempMinID', 0);
        $this->RegisterPropertyInteger('AussenTempMaxID', 0);
        $this->RegisterPropertyInteger('AussenHumID',     0);
        $this->RegisterPropertyInteger('WindRichtungID',  0);
        $this->RegisterPropertyInteger('WindBoenID',      0);
        $this->RegisterPropertyInteger('RegenRateID',     0);
        $this->RegisterPropertyInteger('RegenMenge24ID',  0);
        $this->RegisterPropertyInteger('TaupunktID',      0);
        $this->RegisterPropertyInteger('WetterwarnungID', 0);
        $this->RegisterPropertyInteger('UVID',           0);
        $this->RegisterPropertyInteger('OutdoorLinkID',   0);
        $this->RegisterPropertyInteger('RefreshIntervalMinutes', 5);
        $this->RegisterPropertyFloat('TempWarnMin', 18.0);
        $this->RegisterPropertyFloat('TempWarnMax', 25.0);
        $this->RegisterPropertyFloat('HumidityWarnMin', 30.0);
        $this->RegisterPropertyFloat('HumidityWarnMax', 60.0);
        $this->RegisterPropertyInteger('CO2WarnLevel', 1000);
        $this->RegisterPropertyInteger('CO2AlarmLevel', 1400);
        $this->RegisterPropertyInteger('SoilWarnLevel', 30);
        $this->RegisterPropertyBoolean('HideTitle', false);

        $this->SetVisualizationType(1);

        $this->RegisterTimer('RefreshTimer', 0, 'HomeScreen_Refresh($_IPS[\'TARGET\']);');
        $this->RegisterTimer('DebounceTimer', 0, 'HomeScreen_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->InvalidateRuntimeCaches();
        $this->configurationErrors = $this->ValidateConfiguration();
        if (function_exists('IPS_SetHiddenTitle')) {
            if (!IPS_SetHiddenTitle($this->InstanceID, $this->ReadPropertyBoolean('HideTitle'))) {
                $this->AddConfigurationError('Titelanzeige: Die Einstellung konnte nicht übernommen werden');
            }
        }

        foreach ($this->GetReferenceList() as $ref) {
            $this->UnregisterReference($ref);
        }
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $varIDs = [];

        $bereiche = $this->ReadJsonList('Bereiche');
        foreach ($bereiche as $b) {
            $linkID = (int)($b['LinkID'] ?? 0);
            if ($linkID > 0) {
                $this->RegisterReference($linkID);
            }
            foreach (['LichtID', 'FensterID', 'RolladenID'] as $key) {
                $id = (int)($b[$key] ?? 0);
                if ($id > 0 && $this->VariableExistsCached($id)) {
                    $varIDs[] = $id;
                    $this->RegisterReference($id);
                }
            }
        }

        $raeume = $this->ReadJsonList('Raeume');
        foreach ($raeume as $raum) {
            $linkID = (int)($raum['LinkID'] ?? 0);
            if ($linkID > 0) {
                $this->RegisterReference($linkID);
            }
            foreach (['LichtID', 'FensterID', 'TempID', 'HumID', 'CO2ID',
                      'Geraet1ID', 'Geraet2ID', 'Geraet3ID', 'Geraet4ID'] as $key) {
                $id = (int)($raum[$key] ?? 0);
                if ($id > 0 && $this->VariableExistsCached($id)) {
                    $varIDs[] = $id;
                    $this->RegisterReference($id);
                }
            }
        }

        foreach (['AussenTempID', 'AussenTempMinID', 'AussenTempMaxID', 'AussenHumID',
                  'WindRichtungID', 'WindBoenID', 'RegenRateID', 'RegenMenge24ID', 'TaupunktID', 'WetterwarnungID', 'UVID'] as $key) {
            $id = (int)$this->ReadPropertyInteger($key);
            if ($id > 0 && $this->VariableExistsCached($id)) {
                $varIDs[] = $id;
                $this->RegisterReference($id);
            }
        }
        $outdoorLinkID = (int)$this->ReadPropertyInteger('OutdoorLinkID');
        if ($outdoorLinkID > 0) {
            $this->RegisterReference($outdoorLinkID);
        }

        foreach (['Fahrzeuge', 'EnergieKacheln', 'KlimaGeraete', 'Bewaesserung', 'Lueftungsanlagen', 'Waermepumpen'] as $listKey) {
            $items = $this->ReadJsonList($listKey);
            foreach ($items as $item) {
                $linkID = (int)($item['LinkID'] ?? 0);
                if ($linkID > 0) {
                    $this->RegisterReference($linkID);
                }
                foreach (['SoCID', 'RangeID', 'ChargingID', 'ChargeMinID', 'ChargePowerID', 'StatusID',
                          'SolarID', 'VerbrauchID', 'NetzID', 'BatterieID',
                          'TempID', 'SollTempID', 'ModusID', 'VentilID',
                          'AktivID', 'NextStartID', 'LaufzeitID', 'BodenID', 'BedarfID', 'TagesRestID',
                          'LuefterID', 'LueftModusID', 'FrischluftID', 'ZuluftID', 'BetriebsartID',
                          'TempMitteID', 'TempObenID', 'KompressorID', 'HeizstabID'] as $key) {
                    $id = (int)($item[$key] ?? 0);
                    if ($id > 0 && $this->VariableExistsCached($id)) {
                        $varIDs[] = $id;
                        $this->RegisterReference($id);
                    }
                }
            }
        }

        foreach (array_unique($varIDs) as $id) {
            $this->RegisterMessage($id, VM_UPDATE);
        }

        $refreshMinutes = max(1, $this->ReadPropertyInteger('RefreshIntervalMinutes'));
        $this->SetTimerInterval('RefreshTimer', $refreshMinutes * 60 * 1000);
        $this->SetTimerInterval('DebounceTimer', 0);

        $this->SendVisualizationUpdate($this->GetUpdatePayload(true));
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->pendingUpdateIDs[$SenderID] = true;
            unset($this->trendCache[$SenderID]);
            $this->SetTimerInterval('DebounceTimer', self::UPDATE_DEBOUNCE_MS);
        }
    }

    public function Update(): void
    {
        $this->SetTimerInterval('DebounceTimer', 0);
        $updatedIDs = array_map('intval', array_keys($this->pendingUpdateIDs));
        $this->pendingUpdateIDs = [];
        $this->SendVisualizationUpdate($this->GetUpdatePayload(false, $updatedIDs, $updatedIDs !== []));
    }

    public function Refresh(): void
    {
        // Das Intervall dient nur noch der Uhrzeit/Fallback-Pflege. Werte kommen ereignisgesteuert.
        $this->SendVisualizationUpdate($this->GetUpdatePayload(false, [], false));
    }

    public function ForceUpdate(): void
    {
        $this->pendingUpdateIDs = [];
        $this->SendVisualizationUpdate($this->GetUpdatePayload(false, [], true));
    }

    // -------------------------------------------------------------------------
    // Visualization
    // -------------------------------------------------------------------------

    public function GetVisualizationTile(): string
    {
        $this->BeginRender();
        $this->EnsureConfigurationValidated();
        $bereiche = $this->ReadJsonList('Bereiche');
        $raeume   = $this->ReadJsonList('Raeume');

        $content = $this->BuildContent($bereiche, $raeume);
        $footer  = 'v' . self::MODULE_VERSION . ' · Aktualisiert: ' . date('d.m.Y H:i:s');

        return $this->RenderTile($content, $footer);
    }

    private function GetUpdatePayload(bool $full = false, array $updatedIDs = [], bool $includeParts = true): string
    {
        $this->BeginRender();
        $this->EnsureConfigurationValidated();
        $bereiche = $this->ReadJsonList('Bereiche');
        $raeume   = $this->ReadJsonList('Raeume');

        if ($full) {
            $payload = [
                'type'    => 'full',
                'content' => $this->BuildContent($bereiche, $raeume),
            ];
        } elseif ($includeParts) {
            $payload = [
                'type'  => 'delta',
                'parts' => $this->BuildDeltaParts($bereiche, $raeume, $updatedIDs),
            ];
        } else {
            $payload = [
                'type'  => 'delta',
                'parts' => [],
            ];
        }

        $payload['footer'] = 'v' . self::MODULE_VERSION . ' · Aktualisiert: ' . date('d.m.Y H:i:s');

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    private function SendVisualizationUpdate(string $payload): void
    {
        if (!$this->UpdateVisualizationValue($payload)) {
            // Keine aktive Kachel ist normal, darf aber nicht als Fehler im globalen Log erscheinen.
            $this->SendDebug('UpdateVisualizationValue', 'Keine aktive Visualisierung verbunden', 0);
        }
    }

    private function RenderTile(string $content, string $footer): string
    {
        $safeFooter = $this->EscapeHtml($footer);
        $bodyTopPadding = $this->ReadPropertyBoolean('HideTitle')
            ? self::BODY_PADDING_WITHOUT_TITLE
            : self::BODY_PADDING_WITH_TITLE;

        return <<<HTML
<meta name="viewport" content="width=device-width,initial-scale=1">
<script src="/icons.js"></script>
<style>
  /* Poppins – Symcon Tile Assets */
  @font-face{font-family:'Poppins';src:url('/tile/assets/google_fonts/Poppins-Regular.ttf') format('truetype');font-weight:400;font-style:normal;font-display:swap;}
  @font-face{font-family:'Poppins';src:url('/tile/assets/google_fonts/Poppins-Bold.ttf') format('truetype');font-weight:700;font-style:normal;font-display:swap;}
  /* Symcon stellt automatisch bereit: --accent-color, --content-color, --card-color */
  :root{--text-muted:#999;--group-bg:rgba(0,0,0,0.04);--div-clr:rgba(0,0,0,0.08);--footer:#bbb;}
  *{box-sizing:border-box;margin:0;padding:0;}
  body{background:transparent;color:var(--content-color);font-family:'Poppins',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;margin:0;padding:{$bodyTopPadding} 8px 8px;font-size:13px;}
  .grp{margin-bottom:8px;}
  .grp+.grp{margin-top:10px;}
  .grp-hdr{display:flex;align-items:center;gap:6px;padding:4px 7px;background:var(--group-bg);border-radius:5px;margin-bottom:5px;border-left:3px solid var(--accent-color);}
  .grp-hdr.clickable{cursor:pointer;}
  .grp-hdr.clickable:hover{filter:brightness(0.95);}
  .grp-name{font-size:0.80em;font-weight:600;color:var(--content-color);text-transform:uppercase;letter-spacing:0.05em;flex:1;}
  .grp-chips{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
  .grp-stat{display:inline-flex;align-items:center;gap:2px;font-size:0.80em;}
  .chip-y{background:rgba(255,152,0,0.20);color:#c97000;}
  .chip-r{background:rgba(244,67,54,0.17);color:#c62828;}
  .grid{display:flex;flex-wrap:wrap;gap:6px;}
  .card{background:var(--card-color);border-radius:6px;padding:6px 9px;flex:0 1 auto;min-width:120px;max-width:170px;border:1px solid var(--accent-color);border-left:3px solid transparent;}
  .card.clickable{cursor:pointer;}
  .card.clickable:hover{opacity:0.88;}
  .s-alert{border-left-color:#f44336;}
  .s-warn{border-left-color:#ff9800;}
  .s-charging{border-left-color:#2196f3;}
  .s-active{border-left-color:#4caf50;}
  .s-dehumid{border-left-color:#00bcd4;}
  .s-inactive{border-left-color:#9e9e9e;}
  .c-head{display:flex;justify-content:space-between;align-items:baseline;gap:4px;margin-bottom:4px;}
  .c-name{font-weight:500;color:var(--content-color);font-size:0.95em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .c-temp{font-weight:500;font-size:0.90em;white-space:nowrap;flex-shrink:0;}
  .p-row{display:flex;gap:4px;margin-top:2px;min-height:1.4em;}
  .p-cell{display:flex;align-items:center;gap:2px;font-size:0.85em;flex:1;min-width:0;white-space:nowrap;overflow:hidden;}
  .p-ico{font-size:0.85em;flex-shrink:0;width:1.2em;text-align:center;}
  .p-none{color:var(--text-muted);font-size:0.82em;}
  .ico-muted{color:var(--text-muted);}
  .ico-on{color:#f5a623;}
  .ico-warn{color:#e65c00;}
  .ico-alert{color:#e53935;}
  .ico-charging{color:#2196f3;}
  .ico-solar{color:#f5a623;}
  .ico-grid-in{color:#4caf50;}
  .ico-grid-out{color:#e53935;}
  .ico-active{color:#4caf50;}
  .al-r{color:#e53935;}
  .al-y{color:#e65c00;}
  .al-g{color:#4caf50;}
  .co2dot{display:inline-block;width:7px;height:7px;border-radius:50%;vertical-align:middle;margin-left:2px;flex-shrink:0;}
  .dot-g{background:#4caf50;}.dot-y{background:#e65c00;}.dot-r{background:#e53935;}
  .soc-track{background:rgba(0,0,0,0.10);border-radius:3px;height:4px;margin-top:4px;overflow:hidden;}
  .soc-fill{height:4px;border-radius:3px;transition:width 0.3s;}
  .soc-ok{background:#4caf50;}.soc-low{background:#ff9800;}.soc-crit{background:#f44336;}
  .trend-up{color:#e65c00;font-size:0.72em;vertical-align:middle;}
  .trend-dn{color:#5b9bd5;font-size:0.72em;vertical-align:middle;}
  .trend-st{color:var(--text-muted);font-size:0.72em;vertical-align:middle;}
  /* ── Wetter-Bar ──────────────────────────────────────────── */
  .out-bar{display:flex;align-items:center;flex-wrap:wrap;gap:0;padding:6px 12px;border-radius:6px;margin-bottom:10px;border:1px solid transparent;}
  .out-theme-freeze{background:linear-gradient(135deg,rgba(91,155,213,0.18),rgba(91,155,213,0.06));border-color:rgba(91,155,213,0.3);border-left:3px solid #5b9bd5;}
  .out-theme-cold{background:linear-gradient(135deg,rgba(130,190,220,0.15),rgba(130,190,220,0.05));border-color:rgba(130,190,220,0.25);border-left:3px solid #82bed4;}
  .out-theme-cool{background:linear-gradient(135deg,rgba(100,180,100,0.12),rgba(100,180,100,0.04));border-color:rgba(100,180,100,0.22);border-left:3px solid #64b464;}
  .out-theme-mild{background:linear-gradient(135deg,rgba(76,175,80,0.11),rgba(76,175,80,0.03));border-color:rgba(76,175,80,0.20);border-left:3px solid #4caf50;}
  .out-theme-warm{background:linear-gradient(135deg,rgba(255,167,38,0.14),rgba(255,167,38,0.04));border-color:rgba(255,167,38,0.25);border-left:3px solid #ffa726;}
  .out-theme-hot{background:linear-gradient(135deg,rgba(229,57,53,0.14),rgba(229,57,53,0.04));border-color:rgba(229,57,53,0.25);border-left:3px solid #e53935;}
  .out-icon{font-size:1.5em;flex-shrink:0;line-height:1;margin-right:10px;}
  .out-main{display:flex;align-items:baseline;gap:5px;flex-shrink:0;padding-right:14px;margin-right:14px;border-right:1px solid var(--div-clr);}
  .out-label{font-size:0.70em;font-weight:700;color:var(--content-color);text-transform:uppercase;letter-spacing:0.07em;}
  .out-temp{font-size:1.25em;font-weight:700;color:var(--content-color);line-height:1;}
  .out-cold{color:#5b9bd5;}.out-cool{color:#4a90b8;}.out-warm{color:#e65c00;}.out-hot{color:#e53935;}
  .out-seg{flex:1;display:flex;align-items:center;justify-content:center;padding:0 6px;font-size:0.82em;color:var(--text-muted);border-right:1px solid var(--div-clr);white-space:nowrap;}
  .out-seg:last-child{border-right:none;}
  .out-comfort{font-size:1em;}
  .out-range{display:flex;gap:6px;}
  .out-lo{color:#5b9bd5;font-weight:600;}.out-hi{color:#e53935;font-weight:600;}
  /* ── Mobile: Wetter-Bar 2-zeilig ────────────────────────── */
  @media(max-width:520px){
    .out-bar{padding:6px 10px;}
    .out-icon{font-size:1.25em;margin-right:7px;}
    .out-main{flex-basis:100%;border-right:none;padding-right:0;margin-right:0;padding-bottom:5px;margin-bottom:4px;border-bottom:1px solid var(--div-clr);}
    .out-temp{font-size:1.15em;}
    .out-seg{flex:0 0 auto;border-right:none;padding:2px 8px 0;}
    .out-seg:not(:last-child){border-right:1px solid var(--div-clr);}
  }
  /* ── Status & Footer ─────────────────────────────────────────────── */
  .stat-bar{display:flex;gap:10px;align-items:center;padding:4px 2px;margin-bottom:6px;font-size:0.83em;flex-wrap:wrap;}
  .stat-ok{color:#4caf50;font-weight:600;}
  .stat-al{display:flex;align-items:center;gap:3px;}
  .grp-ok{color:#4caf50;font-size:0.80em;font-weight:600;}
  .empty{color:var(--text-muted);padding:10px;font-size:0.9em;}
  .config-error{color:#c62828;background:rgba(244,67,54,0.10);border:1px solid rgba(244,67,54,0.30);border-left:3px solid #e53935;border-radius:5px;padding:6px 9px;margin-bottom:7px;font-size:0.82em;}
  .config-error ul{margin-left:18px;}
  .grp-alarm{color:#e53935;font-size:0.80em;font-weight:600;}
  .footer{margin-top:8px;font-size:0.67em;color:var(--footer);text-align:right;}
  .out-bar.clickable{cursor:pointer;}.out-bar.clickable:hover{filter:brightness(0.96);}
  .out-warn{display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:3px;font-size:0.75em;font-weight:600;}
  .out-warn-0{background:#4caf50;color:#fff;}
  .out-warn-1{background:#f9a825;color:#333;}
  .out-warn-2{background:#e65c00;color:#fff;}
  .out-warn-3{background:#c62828;color:#fff;}
  .out-warn-4{background:#4a0000;color:#fff;}
  .out-warn-10{background:#e91e63;color:#fff;}
  .out-warn-11{background:#7b1fa2;color:#fff;}
  .out-warn-seg{flex-direction:column;align-items:flex-start;gap:3px;}
  .out-uv{display:inline-flex;align-items:center;gap:3px;padding:1px 6px;border-radius:3px;font-size:0.75em;font-weight:700;}
  .uv-low{background:#4caf50;color:#fff;}
  .uv-mid{background:#f9a825;color:#333;}
  .uv-high{background:#e65c00;color:#fff;}
  .uv-veryhigh{background:#e53935;color:#fff;}
  .uv-extreme{background:#7b1fa2;color:#fff;}
  .out-row2{display:flex;width:100%;margin-top:5px;padding-top:5px;border-top:1px solid var(--div-clr);}
  .out-row2 .out-seg{padding:0 4px;font-size:0.80em;}
  /* Icon-Symbole – kein externer CDN, kein Internet erforderlich */
  .fa-solid{font-style:normal;display:inline-block;line-height:1;}
  .fa-solid::before{font-family:system-ui,'Segoe UI Symbol','Apple Symbols','Noto Sans',sans-serif;}
  .fa-check::before{content:"✓";}
  .fa-lightbulb::before{content:"◉";}
  .fa-door-open::before{content:"⊏";}
  .fa-door-closed::before{content:"⊐";}
  .fa-temperature-half::before{content:"▾";}
  .fa-temperature-high::before{content:"▴";}
  .fa-wind::before{content:"≈";}
  .fa-bars::before{content:"≡";}
  .fa-plug::before{content:"⊓";}
  .fa-car::before{content:"▶";}
  .fa-bolt::before{content:"↯";}
  .fa-road::before{content:"↕";}
  .fa-sun::before{content:"✦";}
  .fa-house::before{content:"⌂";}
  .fa-plug-circle-bolt::before{content:"⊛";}
  .fa-battery-half::before{content:"▬";}
  .fa-sliders::before{content:"≣";}
  .fa-circle-half-stroke::before{content:"◑";}
  .fa-fan::before{content:"✧";}
  .fa-droplet::before{content:"◉";}
  .fa-clock::before{content:"◔";}
  .fa-hourglass-half::before{content:"▽";}
  .fa-chart-simple::before{content:"▲";}
  .fa-calendar::before{content:"⊟";}
  .fa-seedling::before{content:"✿";}
  .fa-arrow-right-to-bracket::before{content:"→";}
  .fa-arrow-right-from-bracket::before{content:"←";}
  .fa-gear::before{content:"⚙";font-variant-emoji:text;}
  .fa-compass::before{content:"⊕";}
  .fa-cloud-rain::before{content:"≈";}
  .fa-cloud-showers-heavy::before{content:"≋";}
  .fa-shield-halved::before{content:"◈";}
  .fa-triangle-exclamation::before{content:"△";}
  .fa-arrow-right::before{content:"→";}
  .fa-arrow-trend-up::before{content:"↗";}
  .fa-arrow-trend-down::before{content:"↘";}
</style>
<div id="cis-content">{$content}</div>
<div id="cis-footer" class="footer">{$safeFooter}</div>
<script>
function handleMessage(data){
  try {
    var d=typeof data==='string' ? JSON.parse(data) : data;
    var content=document.getElementById('cis-content');
    var footer=document.getElementById('cis-footer');
    if(d && d.type==='full' && d.content!==undefined && content)content.innerHTML=d.content;
    if(d && d.type==='delta' && d.parts){
      if(d.parts.outdoor!==undefined)replacePart('cis-outdoor',d.parts.outdoor);
      if(d.parts.globalStatus!==undefined)replacePart('cis-global-status',d.parts.globalStatus);
      if(d.parts.groups){
        Object.keys(d.parts.groups).forEach(function(key){replacePart('cis-group-'+key,d.parts.groups[key]);});
      }
    }
    // Kompatibilität mit älteren Update-Payloads.
    if(d && !d.type && d.content!==undefined && content)content.innerHTML=d.content;
    if(d && d.footer!==undefined && footer)footer.textContent=d.footer;
  } catch(e) {
    console.warn('Central Info Screen: ungültige Aktualisierungsdaten', e);
  }
}
function replacePart(id,html){
  var current=document.getElementById(id);
  if(!current || typeof html!=='string')return;
  var template=document.createElement('template');
  template.innerHTML=html.trim();
  var replacement=template.content.firstElementChild;
  if(replacement)current.replaceWith(replacement);
}
</script>
HTML;
    }

    // -------------------------------------------------------------------------
    // Konfigurationsformular
    // -------------------------------------------------------------------------

    public function GetConfigurationForm(): string
    {
        $bereiche = $this->ReadJsonList('Bereiche');
        $this->SortByPosition($bereiche);
        $bereichOptionen = [['caption' => '– kein Bereich –', 'value' => '']];
        foreach ($bereiche as $b) {
            $name = trim($b['Name'] ?? '');
            if ($name !== '') {
                $bereichOptionen[] = ['caption' => $name, 'value' => $name];
            }
        }

        $slotOptionen = [
            ['caption' => '– leer –',   'value' => ''],
            ['caption' => 'Temperatur', 'value' => 'temp'],
            ['caption' => 'Licht',      'value' => 'licht'],
            ['caption' => 'Fenster',    'value' => 'fenster'],
            ['caption' => 'Luftfeuchte','value' => 'hum'],
            ['caption' => 'CO₂',        'value' => 'co2'],
            ['caption' => 'Gerät 1',    'value' => 'geraet1'],
            ['caption' => 'Gerät 2',    'value' => 'geraet2'],
            ['caption' => 'Gerät 3',    'value' => 'geraet3'],
            ['caption' => 'Gerät 4',    'value' => 'geraet4'],
        ];

        return json_encode([
            'elements' => [
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Anzeige / Grenzwerte',
                    'items'   => [
                        ['type' => 'CheckBox', 'name' => 'HideTitle', 'caption' => 'Titel ausblenden und oberen Abstand entfernen'],
                        ['type' => 'NumberSpinner', 'name' => 'RefreshIntervalMinutes', 'caption' => 'Aktualisierungsintervall (Minuten)', 'minimum' => 1, 'maximum' => 60],
                        ['type' => 'NumberSpinner', 'name' => 'TempWarnMin', 'caption' => 'Temperatur-Warnung ab (°C)', 'minimum' => -50, 'maximum' => 80, 'digits' => 1],
                        ['type' => 'NumberSpinner', 'name' => 'TempWarnMax', 'caption' => 'Temperatur-Warnung über (°C)', 'minimum' => -50, 'maximum' => 80, 'digits' => 1],
                        ['type' => 'NumberSpinner', 'name' => 'HumidityWarnMin', 'caption' => 'Luftfeuchte-Warnung unter (%)', 'minimum' => 0, 'maximum' => 100, 'digits' => 1],
                        ['type' => 'NumberSpinner', 'name' => 'HumidityWarnMax', 'caption' => 'Luftfeuchte-Warnung über (%)', 'minimum' => 0, 'maximum' => 100, 'digits' => 1],
                        ['type' => 'NumberSpinner', 'name' => 'CO2WarnLevel', 'caption' => 'CO₂-Warnung ab (ppm)', 'minimum' => 0, 'maximum' => 10000],
                        ['type' => 'NumberSpinner', 'name' => 'CO2AlarmLevel', 'caption' => 'CO₂-Alarm über (ppm)', 'minimum' => 0, 'maximum' => 10000],
                        ['type' => 'NumberSpinner', 'name' => 'SoilWarnLevel', 'caption' => 'Bodenfeuchte-Warnung unter (%)', 'minimum' => 0, 'maximum' => 100],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Außen / Wetter',
                    'items'   => [
                        ['type' => 'SelectVariable', 'name' => 'AussenTempID',    'caption' => 'Außentemperatur'],
                        ['type' => 'SelectVariable', 'name' => 'AussenHumID',     'caption' => 'Außenluftfeuchtigkeit (optional)'],
                        ['type' => 'SelectVariable', 'name' => 'AussenTempMinID', 'caption' => 'Tages-Tiefstwert (optional)'],
                        ['type' => 'SelectVariable', 'name' => 'AussenTempMaxID', 'caption' => 'Tages-Höchstwert (optional)'],
                        ['type' => 'SelectVariable', 'name' => 'WindRichtungID',  'caption' => 'Windrichtung (optional)'],
                        ['type' => 'SelectVariable', 'name' => 'WindBoenID',      'caption' => 'Windböen km/h (optional)'],
                        ['type' => 'SelectVariable', 'name' => 'RegenRateID',     'caption' => 'Regenrate mm/h (optional)'],
                        ['type' => 'SelectVariable', 'name' => 'RegenMenge24ID',  'caption' => 'Regenmenge 24h mm (optional)'],
                        ['type' => 'SelectVariable', 'name' => 'TaupunktID',      'caption' => 'Taupunkt °C (optional, sonst berechnet)'],
                        ['type' => 'SelectVariable', 'name' => 'WetterwarnungID', 'caption' => 'Wetterwarnung (Integer 0–13, optional)'],
                        ['type' => 'SelectVariable', 'name' => 'UVID',           'caption' => 'UV-Index (Integer 0–11+, optional)'],
                        ['type' => 'SelectObject',   'name' => 'OutdoorLinkID',   'caption' => 'Navigation (Klick, optional)'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Bereiche / Stockwerke',
                    'items'   => [[
                        'type'     => 'List',
                        'name'     => 'Bereiche',
                        'caption'  => 'Bereiche (Reihenfolge über Pos.-Nummer)',
                        'add'      => true,
                        'delete'   => true,
                        'rowCount' => 8,
                        'columns'  => [
                            ['caption' => 'Pos.',                'name' => 'Position',  'width' => '50px',  'add' => 0,               'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 999]],
                            ['caption' => 'Name',                'name' => 'Name',      'width' => '120px', 'add' => 'Neues Stockwerk','edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Navigation (Klick)',  'name' => 'LinkID',    'width' => '150px', 'add' => 0,               'edit' => ['type' => 'SelectObject']],
                            ['caption' => 'Licht (Anzahl/Bool)', 'name' => 'LichtID',   'width' => '140px', 'add' => 0,               'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Fenster (Anzahl)',    'name' => 'FensterID', 'width' => '140px', 'add' => 0,               'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Rolladen (Anzahl)',   'name' => 'RolladenID','width' => '140px', 'add' => 0,               'edit' => ['type' => 'SelectVariable']],
                        ],
                    ]],
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => 'Räume',
                    'expanded' => true,
                    'items'    => [[
                        'type'     => 'List',
                        'name'     => 'Raeume',
                        'caption'  => 'Räume (Reihenfolge über Pos.-Nummer)',
                        'add'      => true,
                        'delete'   => true,
                        'rowCount' => 8,
                        'columns'  => [
                            ['caption' => 'Pos.',             'name' => 'Position',    'width' => '50px',  'add' => 0,          'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 999]],
                            ['caption' => 'Bereich',          'name' => 'Bereich',     'width' => '110px', 'add' => '',         'edit' => ['type' => 'Select', 'options' => $bereichOptionen]],
                            ['caption' => 'Raumname',         'name' => 'Name',        'width' => '110px', 'add' => 'Neuer Raum','edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Navigation',       'name' => 'LinkID',      'width' => '120px', 'add' => 0,          'edit' => ['type' => 'SelectObject']],
                            ['caption' => 'Licht',            'name' => 'LichtID',     'width' => '110px', 'add' => 0,          'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Inv.',             'name' => 'LichtInvert', 'width' => '40px',  'add' => false,      'edit' => ['type' => 'CheckBox']],
                            ['caption' => 'Fenster',          'name' => 'FensterID',   'width' => '110px', 'add' => 0,          'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Inv.',             'name' => 'FensterInvert','width'=> '40px',  'add' => false,      'edit' => ['type' => 'CheckBox']],
                            ['caption' => 'Temperatur (°C)',  'name' => 'TempID',      'width' => '110px', 'add' => 0,          'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Luftfeuchte (%)',  'name' => 'HumID',       'width' => '110px', 'add' => 0,          'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'CO₂ (ppm)',        'name' => 'CO2ID',       'width' => '100px', 'add' => 0,          'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Slot 1',           'name' => 'Slot1',       'width' => '90px',  'add' => 'licht',    'edit' => ['type' => 'Select', 'options' => $slotOptionen]],
                            ['caption' => 'Slot 2',           'name' => 'Slot2',       'width' => '90px',  'add' => 'fenster',  'edit' => ['type' => 'Select', 'options' => $slotOptionen]],
                            ['caption' => 'Slot 3',           'name' => 'Slot3',       'width' => '90px',  'add' => 'hum',      'edit' => ['type' => 'Select', 'options' => $slotOptionen]],
                            ['caption' => 'Slot 4',           'name' => 'Slot4',       'width' => '90px',  'add' => 'co2',      'edit' => ['type' => 'Select', 'options' => $slotOptionen]],
                            ['caption' => 'Gerät 1',          'name' => 'Geraet1ID',   'width' => '110px', 'add' => 0,          'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Gerät 1 Label',    'name' => 'Geraet1Name', 'width' => '100px', 'add' => '',         'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Gerät 2',          'name' => 'Geraet2ID',   'width' => '110px', 'add' => 0,          'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Gerät 2 Label',    'name' => 'Geraet2Name', 'width' => '100px', 'add' => '',         'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Gerät 3',          'name' => 'Geraet3ID',   'width' => '110px', 'add' => 0,          'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Gerät 3 Label',    'name' => 'Geraet3Name', 'width' => '100px', 'add' => '',         'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Gerät 4',          'name' => 'Geraet4ID',   'width' => '110px', 'add' => 0,          'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Gerät 4 Label',    'name' => 'Geraet4Name', 'width' => '100px', 'add' => '',         'edit' => ['type' => 'ValidationTextBox']],
                        ],
                    ]],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Fahrzeuge (E-Auto)',
                    'items'   => [[
                        'type'     => 'List',
                        'name'     => 'Fahrzeuge',
                        'caption'  => 'Fahrzeuge',
                        'add'      => true,
                        'delete'   => true,
                        'rowCount' => 6,
                        'columns'  => [
                            ['caption' => 'Pos.',           'name' => 'Position',    'width' => '50px',  'add' => 0,           'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 999]],
                            ['caption' => 'Bereich',        'name' => 'Bereich',     'width' => '110px', 'add' => '',          'edit' => ['type' => 'Select', 'options' => $bereichOptionen]],
                            ['caption' => 'Name',           'name' => 'Name',        'width' => '110px', 'add' => 'Fahrzeug',  'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Navigation',     'name' => 'LinkID',      'width' => '120px', 'add' => 0,           'edit' => ['type' => 'SelectObject']],
                            ['caption' => 'Batteriestand%', 'name' => 'SoCID',       'width' => '120px', 'add' => 0,           'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Reichweite (km)','name' => 'RangeID',     'width' => '120px', 'add' => 0,           'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Status (Text)',  'name' => 'StatusID',    'width' => '120px', 'add' => 0,           'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Lädt (Bool)',       'name' => 'ChargingID',    'width' => '110px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Restladezeit (Sek)','name'=> 'ChargeMinID',  'width' => '130px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Ladeleistung (W)',  'name'=> 'ChargePowerID','width' => '130px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                        ],
                    ]],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Energie / Solar',
                    'items'   => [[
                        'type'     => 'List',
                        'name'     => 'EnergieKacheln',
                        'caption'  => 'Energie-Kacheln',
                        'add'      => true,
                        'delete'   => true,
                        'rowCount' => 6,
                        'columns'  => [
                            ['caption' => 'Pos.',            'name' => 'Position',   'width' => '50px',  'add' => 0,       'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 999]],
                            ['caption' => 'Bereich',         'name' => 'Bereich',    'width' => '110px', 'add' => '',      'edit' => ['type' => 'Select', 'options' => $bereichOptionen]],
                            ['caption' => 'Name',            'name' => 'Name',       'width' => '110px', 'add' => 'Solar', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Navigation',      'name' => 'LinkID',     'width' => '120px', 'add' => 0,       'edit' => ['type' => 'SelectObject']],
                            ['caption' => 'Solar (W)',       'name' => 'SolarID',    'width' => '110px', 'add' => 0,       'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Verbrauch (W)',   'name' => 'VerbrauchID','width' => '110px', 'add' => 0,       'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Netz (W)',        'name' => 'NetzID',     'width' => '110px', 'add' => 0,       'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Batterie (%)',    'name' => 'BatterieID', 'width' => '110px', 'add' => 0,       'edit' => ['type' => 'SelectVariable']],
                        ],
                    ]],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Klima / Thermostat',
                    'items'   => [[
                        'type'     => 'List',
                        'name'     => 'KlimaGeraete',
                        'caption'  => 'Klima-Geräte',
                        'add'      => true,
                        'delete'   => true,
                        'rowCount' => 6,
                        'columns'  => [
                            ['caption' => 'Pos.',            'name' => 'Position',  'width' => '50px',  'add' => 0,       'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 999]],
                            ['caption' => 'Bereich',         'name' => 'Bereich',   'width' => '110px', 'add' => '',      'edit' => ['type' => 'Select', 'options' => $bereichOptionen]],
                            ['caption' => 'Name',            'name' => 'Name',      'width' => '110px', 'add' => 'Klima', 'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Navigation',      'name' => 'LinkID',    'width' => '120px', 'add' => 0,       'edit' => ['type' => 'SelectObject']],
                            ['caption' => 'Ist-Temp (°C)',   'name' => 'TempID',    'width' => '110px', 'add' => 0,       'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Soll-Temp (°C)',  'name' => 'SollTempID','width' => '110px', 'add' => 0,       'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Modus (Text)',         'name' => 'ModusID',   'width' => '110px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'An/Aus (Bool)',        'name' => 'AktivID',   'width' => '110px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Lüftermodus (String)', 'name' => 'VentilID',  'width' => '110px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                        ],
                    ]],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Bewässerung',
                    'items'   => [[
                        'type'     => 'List',
                        'name'     => 'Bewaesserung',
                        'caption'  => 'Bewässerungs-Zonen',
                        'add'      => true,
                        'delete'   => true,
                        'rowCount' => 6,
                        'columns'  => [
                            ['caption' => 'Pos.',              'name' => 'Position',   'width' => '50px',  'add' => 0,       'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 999]],
                            ['caption' => 'Bereich',           'name' => 'Bereich',    'width' => '110px', 'add' => '',      'edit' => ['type' => 'Select', 'options' => $bereichOptionen]],
                            ['caption' => 'Name',              'name' => 'Name',       'width' => '110px', 'add' => 'Zone',  'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Navigation',        'name' => 'LinkID',     'width' => '120px', 'add' => 0,       'edit' => ['type' => 'SelectObject']],
                            ['caption' => 'Aktiv (Bool)',      'name' => 'AktivID',    'width' => '110px', 'add' => 0,       'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Nächster Start',       'name' => 'NextStartID','width' => '120px', 'add' => 0,       'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Restlaufzeit (Sek)',   'name' => 'LaufzeitID', 'width' => '130px', 'add' => 0,       'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Bodenfeuchte (%)',     'name' => 'BodenID',    'width' => '120px', 'add' => 0,       'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Heutiger Bedarf',      'name' => 'BedarfID',   'width' => '130px', 'add' => 0,       'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Heutige Restlaufzeit', 'name' => 'TagesRestID','width' => '140px', 'add' => 0,       'edit' => ['type' => 'SelectVariable']],
                        ],
                    ]],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Lüftungsanlage',
                    'items'   => [[
                        'type'     => 'List',
                        'name'     => 'Lueftungsanlagen',
                        'caption'  => 'Lüftungsanlagen',
                        'add'      => true,
                        'delete'   => true,
                        'rowCount' => 6,
                        'columns'  => [
                            ['caption' => 'Pos.',             'name' => 'Position',     'width' => '50px',  'add' => 0,            'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 999]],
                            ['caption' => 'Bereich',          'name' => 'Bereich',      'width' => '110px', 'add' => '',           'edit' => ['type' => 'Select', 'options' => $bereichOptionen]],
                            ['caption' => 'Name',             'name' => 'Name',         'width' => '110px', 'add' => 'Lüftung',    'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Navigation',       'name' => 'LinkID',       'width' => '120px', 'add' => 0,            'edit' => ['type' => 'SelectObject']],
                            ['caption' => 'Lüfterstufe',      'name' => 'LuefterID',    'width' => '120px', 'add' => 0,            'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Betriebsmodus',    'name' => 'LueftModusID', 'width' => '120px', 'add' => 0,            'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Frischluft (°C)',  'name' => 'FrischluftID', 'width' => '120px', 'add' => 0,            'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Zuluft (°C)',      'name' => 'ZuluftID',     'width' => '120px', 'add' => 0,            'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Betriebsart',      'name' => 'BetriebsartID','width' => '120px', 'add' => 0,            'edit' => ['type' => 'SelectVariable']],
                        ],
                    ]],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Warmwasser-Wärmepumpe',
                    'items'   => [[
                        'type'     => 'List',
                        'name'     => 'Waermepumpen',
                        'caption'  => 'Wärmepumpen',
                        'add'      => true,
                        'delete'   => true,
                        'rowCount' => 6,
                        'columns'  => [
                            ['caption' => 'Pos.',              'name' => 'Position',    'width' => '50px',  'add' => 0,              'edit' => ['type' => 'NumberSpinner', 'minimum' => 0, 'maximum' => 999]],
                            ['caption' => 'Bereich',           'name' => 'Bereich',     'width' => '110px', 'add' => '',             'edit' => ['type' => 'Select', 'options' => $bereichOptionen]],
                            ['caption' => 'Name',              'name' => 'Name',        'width' => '110px', 'add' => 'Wärmepumpe',   'edit' => ['type' => 'ValidationTextBox']],
                            ['caption' => 'Navigation',        'name' => 'LinkID',      'width' => '120px', 'add' => 0,              'edit' => ['type' => 'SelectObject']],
                            ['caption' => 'Temp. Mitte (°C)',  'name' => 'TempMitteID', 'width' => '130px', 'add' => 0,              'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Temp. Oben (°C)',   'name' => 'TempObenID',  'width' => '130px', 'add' => 0,              'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Zustand Kompressor','name' => 'KompressorID','width' => '140px', 'add' => 0,              'edit' => ['type' => 'SelectVariable']],
                            ['caption' => 'Zustand Heizstab',  'name' => 'HeizstabID',  'width' => '130px', 'add' => 0,              'edit' => ['type' => 'SelectVariable']],
                        ],
                    ]],
                ],
            ],
            'actions' => [
                [
                    'type'    => 'Button',
                    'caption' => 'Jetzt aktualisieren',
                    'onClick' => 'HomeScreen_ForceUpdate($id);',
                ],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // HTML-Generierung
    // -------------------------------------------------------------------------

    private function BeginRender(): void
    {
        $this->renderValueCache = [];
        $this->renderFormattedValueCache = [];
        $this->renderVariableInfoCache = [];
        $this->renderVariableExistsCache = [];
    }

    private function InvalidateRuntimeCaches(): void
    {
        $this->jsonListCache = [];
        $this->trendCache = [];
        $this->configurationErrors = [];
        $this->configurationValidated = false;
        $this->pendingUpdateIDs = [];
        $this->BeginRender();
    }

    private function EnsureConfigurationValidated(): void
    {
        if (!$this->configurationValidated) {
            $this->configurationErrors = $this->ValidateConfiguration();
        }
    }

    private function VariableExistsCached(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        if (!array_key_exists($id, $this->renderVariableExistsCache)) {
            $this->renderVariableExistsCache[$id] = IPS_VariableExists($id);
        }
        return $this->renderVariableExistsCache[$id];
    }

    private function GetVariableInfoCached(int $id): array
    {
        if (!array_key_exists($id, $this->renderVariableInfoCache)) {
            $this->renderVariableInfoCache[$id] = IPS_GetVariable($id);
        }
        return $this->renderVariableInfoCache[$id];
    }

    private function GetCachedValue(int $id): mixed
    {
        if ($id <= 0) {
            return null;
        }
        if (array_key_exists($id, $this->renderValueCache)) {
            return $this->renderValueCache[$id];
        }

        $type = (int)($this->GetVariableInfoCached($id)['VariableType'] ?? -1);
        $value = match ($type) {
            0       => GetValueBoolean($id),
            1       => GetValueInteger($id),
            2       => GetValueFloat($id),
            3       => GetValueString($id),
            default => GetValue($id),
        };
        $this->renderValueCache[$id] = $value;
        return $value;
    }

    private function GetCachedFormattedValue(int $id): string
    {
        if ($id <= 0) {
            return '';
        }
        if (!array_key_exists($id, $this->renderFormattedValueCache)) {
            $this->renderFormattedValueCache[$id] = (string)GetValueFormatted($id);
        }
        return $this->renderFormattedValueCache[$id];
    }

    private function EscapeHtml(mixed $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function AddConfigurationError(string $message): void
    {
        if (!in_array($message, $this->configurationErrors, true)) {
            $this->configurationErrors[] = $message;
        }
    }

    private function ReadJsonList(string $property): array
    {
        $raw = $this->ReadPropertyString($property);
        if (isset($this->jsonListCache[$property]) && $this->jsonListCache[$property]['raw'] === $raw) {
            foreach ($this->jsonListCache[$property]['errors'] as $error) {
                $this->AddConfigurationError($error);
            }
            return $this->jsonListCache[$property]['items'];
        }

        $errors = [];
        try {
            $value = json_decode(
                $raw,
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (Throwable $exception) {
            $errors[] = $property . ': ungültiges JSON (' . $exception->getMessage() . ')';
            $this->jsonListCache[$property] = ['raw' => $raw, 'items' => [], 'errors' => $errors];
            foreach ($errors as $error) {
                $this->AddConfigurationError($error);
            }
            return [];
        }

        if (!is_array($value)) {
            $errors[] = $property . ': erwartet wird eine Liste';
            $this->jsonListCache[$property] = ['raw' => $raw, 'items' => [], 'errors' => $errors];
            foreach ($errors as $error) {
                $this->AddConfigurationError($error);
            }
            return [];
        }

        $validItems = [];
        foreach ($value as $index => $item) {
            if (!is_array($item)) {
                $errors[] = $property . '[' . $index . ']: ungültiger Listeneintrag';
                continue;
            }
            $validItems[] = $item;
        }

        $this->jsonListCache[$property] = ['raw' => $raw, 'items' => $validItems, 'errors' => $errors];
        foreach ($errors as $error) {
            $this->AddConfigurationError($error);
        }
        return $validItems;
    }

    private function ListLabel(string $listKey): string
    {
        return [
            'Bereiche' => 'Bereiche',
            'Raeume' => 'Räume',
            'Fahrzeuge' => 'Fahrzeuge',
            'EnergieKacheln' => 'Energie-Kacheln',
            'KlimaGeraete' => 'Klima-Geräte',
            'Bewaesserung' => 'Bewässerung',
            'Lueftungsanlagen' => 'Lüftungsanlagen',
            'Waermepumpen' => 'Warmwasser-Wärmepumpen',
            'Aussen' => 'Außen / Wetter',
        ][$listKey] ?? $listKey;
    }

    private function FieldLabel(string $listKey, string $field): string
    {
        $labels = [
            'Bereiche' => ['LinkID' => 'Navigation', 'LichtID' => 'Licht', 'FensterID' => 'Fenster', 'RolladenID' => 'Rollladen'],
            'Raeume' => [
                'LichtID' => 'Licht', 'FensterID' => 'Fenster', 'TempID' => 'Temperatur', 'HumID' => 'Luftfeuchte',
                'CO2ID' => 'CO₂', 'Geraet1ID' => 'Gerät 1', 'Geraet2ID' => 'Gerät 2', 'Geraet3ID' => 'Gerät 3', 'Geraet4ID' => 'Gerät 4',
            ],
            'Fahrzeuge' => [
                'LinkID' => 'Navigation', 'SoCID' => 'Batteriestand (SoC)', 'RangeID' => 'Reichweite', 'ChargingID' => 'Ladestatus',
                'ChargeMinID' => 'Restladezeit', 'ChargePowerID' => 'Ladeleistung', 'StatusID' => 'Status',
            ],
            'EnergieKacheln' => ['LinkID' => 'Navigation', 'SolarID' => 'Solarleistung', 'VerbrauchID' => 'Verbrauch', 'NetzID' => 'Netzleistung', 'BatterieID' => 'Batteriestand'],
            'KlimaGeraete' => ['LinkID' => 'Navigation', 'TempID' => 'Ist-Temperatur', 'SollTempID' => 'Soll-Temperatur', 'ModusID' => 'Modus', 'AktivID' => 'Aktivstatus', 'VentilID' => 'Ventil / Gebläse'],
            'Bewaesserung' => ['LinkID' => 'Navigation', 'AktivID' => 'Aktivstatus', 'NextStartID' => 'Nächster Start', 'LaufzeitID' => 'Restlaufzeit', 'BodenID' => 'Bodenfeuchte', 'BedarfID' => 'Bedarf', 'TagesRestID' => 'Tagesrest'],
            'Lueftungsanlagen' => ['LinkID' => 'Navigation', 'LuefterID' => 'Lüfterstufe', 'LueftModusID' => 'Lüftermodus', 'FrischluftID' => 'Frischlufttemperatur', 'ZuluftID' => 'Zulufttemperatur', 'BetriebsartID' => 'Betriebsart'],
            'Waermepumpen' => ['LinkID' => 'Navigation', 'TempMitteID' => 'Temperatur Mitte', 'TempObenID' => 'Temperatur oben', 'KompressorID' => 'Kompressorstatus', 'HeizstabID' => 'Heizstabstatus'],
            'Aussen' => [
                'AussenTempID' => 'Außentemperatur', 'AussenTempMinID' => 'Tages-Tiefstwert', 'AussenTempMaxID' => 'Tages-Höchstwert',
                'AussenHumID' => 'Außenluftfeuchte', 'WindRichtungID' => 'Windrichtung', 'WindBoenID' => 'Windböen', 'RegenRateID' => 'Regenrate',
                'RegenMenge24ID' => 'Regenmenge 24h', 'TaupunktID' => 'Taupunkt', 'WetterwarnungID' => 'Wetterwarnung', 'UVID' => 'UV-Index',
            ],
        ];

        return $labels[$listKey][$field] ?? $field;
    }

    private function ConfigurationContext(string $listKey, int|string $index, array $item = []): string
    {
        $name = trim((string)($item['Name'] ?? ''));
        return $this->ListLabel($listKey) . '[' . $index . ']' . ($name !== '' ? ' „' . $name . '“' : '');
    }

    private function VariableTypeLabel(int $type): string
    {
        return match ($type) {
            0 => 'Boolean',
            1 => 'Integer',
            2 => 'Float',
            3 => 'String',
            default => 'unbekannt',
        };
    }

    private function ExpectedTypeLabel(array $types): string
    {
        return implode(' oder ', array_map(
            fn(int $type): string => $this->VariableTypeLabel($type) . ' (' . $type . ')',
            $types
        ));
    }

    private function ValidateLinkID(array &$errors, string $context, mixed $rawID): void
    {
        if ($rawID === null || $rawID === '' || (int)$rawID === 0) {
            return;
        }

        if (!is_numeric($rawID)) {
            $errors[] = $context . ': Objekt-ID muss eine Zahl sein. Prüfen: Navigation leeren oder ein gültiges IPS-Objekt auswählen.';
            return;
        }

        $id = (int)$rawID;
        if ($id <= 0 || !IPS_ObjectExists($id)) {
            $errors[] = $context . ': Objekt-ID ' . $id . ' existiert nicht. Prüfen: Navigation leeren oder ein gültiges IPS-Objekt auswählen.';
        }
    }

    private function ExpectedVariableTypes(string $listKey, string $field): array
    {
        $key = $listKey . '.' . $field;

        $booleanFields = [
            'Fahrzeuge.ChargingID',
            'KlimaGeraete.AktivID',
            'Bewaesserung.AktivID',
            'Waermepumpen.KompressorID', 'Waermepumpen.HeizstabID',
        ];
        if (in_array($key, $booleanFields, true)) {
            return [0, 1];
        }
        if (in_array($key, [
            'Raeume.Geraet1ID', 'Raeume.Geraet2ID', 'Raeume.Geraet3ID', 'Raeume.Geraet4ID',
        ], true)) {
            return [0];
        }
        if ($key === 'Lueftungsanlagen.LuefterID') {
            return [0, 1, 2, 3];
        }

        $numericFields = [
            'Raeume.TempID', 'Raeume.HumID', 'Raeume.CO2ID',
            'Fahrzeuge.SoCID', 'Fahrzeuge.RangeID', 'Fahrzeuge.ChargeMinID', 'Fahrzeuge.ChargePowerID',
            'EnergieKacheln.SolarID', 'EnergieKacheln.VerbrauchID', 'EnergieKacheln.NetzID', 'EnergieKacheln.BatterieID',
            'KlimaGeraete.TempID', 'KlimaGeraete.SollTempID',
            'Bewaesserung.LaufzeitID', 'Bewaesserung.BodenID', 'Bewaesserung.BedarfID', 'Bewaesserung.TagesRestID',
            'Lueftungsanlagen.FrischluftID', 'Lueftungsanlagen.ZuluftID',
            'Waermepumpen.TempMitteID', 'Waermepumpen.TempObenID',
        ];
        return in_array($key, $numericFields, true) ? [1, 2] : [];
    }

    private function IsEmptyVariableSelection(mixed $rawID): bool
    {
        if ($rawID === null || $rawID === '') {
            return true;
        }
        if (!is_numeric($rawID)) {
            return false;
        }

        $id = (int)$rawID;
        if ($id === 0) {
            return true;
        }

        // Ältere SelectVariable-Konfigurationen können „Kein(e)“ als ID 1 speichern.
        return $id === 1 && !$this->VariableExistsCached(1);
    }

    private function ValidateVariableID(array &$errors, string $context, mixed $rawID, array $expectedTypes = []): void
    {
        if ($this->IsEmptyVariableSelection($rawID)) {
            return;
        }

        if (!is_numeric($rawID)) {
            $errors[] = $context . ': Variablen-ID muss eine Zahl sein. Prüfen: eine vorhandene Variable auswählen oder das Feld leeren.';
            return;
        }

        $id = (int)$rawID;
        if ($id <= 0 || !$this->VariableExistsCached($id)) {
            $errors[] = $context . ': Variablen-ID ' . $id . ' existiert nicht. Prüfen: eine vorhandene Variable auswählen oder das Feld leeren.';
            return;
        }

        if ($expectedTypes !== []) {
            $variable = $this->GetVariableInfoCached($id);
            $type = (int)($variable['VariableType'] ?? -1);
            if (!in_array($type, $expectedTypes, true)) {
                $variableName = trim((string)IPS_GetName($id));
                $nameSuffix = $variableName !== '' ? ' „' . $variableName . '“' : '';
                $actual = $this->VariableTypeLabel($type) . ' (' . $type . ')';
                $expected = $this->ExpectedTypeLabel($expectedTypes);
                $errors[] = $context . ': Variablen-ID ' . $id . $nameSuffix . ' hat Typ ' . $actual . ', erwartet wird ' . $expected . '. Prüfen: eine Variable vom erwarteten Typ auswählen oder das Feld leeren.';
            }
        }
    }

    private function ValidateConfiguration(): array
    {
        $errors = [];
        $this->configurationErrors = [];

        $bereiche = $this->ReadJsonList('Bereiche');
        $bereichNamen = [];
        foreach ($bereiche as $index => $bereich) {
            $name = trim((string)($bereich['Name'] ?? ''));
            $context = $this->ConfigurationContext('Bereiche', $index, $bereich);
            if ($name !== '' && in_array($name, $bereichNamen, true)) {
                $errors[] = $context . ': Name ist doppelt. Prüfen: Bereich umbenennen, damit jeder Bereich eindeutig ist.';
            }
            if ($name !== '') {
                $bereichNamen[] = $name;
            }
            $this->ValidateLinkID($errors, $context . ' → Navigation', $bereich['LinkID'] ?? 0);
            foreach (['LichtID', 'FensterID', 'RolladenID'] as $field) {
                $this->ValidateVariableID($errors, $context . ' → ' . $this->FieldLabel('Bereiche', $field), $bereich[$field] ?? 0);
            }
        }

        $listFields = [
            'Raeume' => ['LichtID', 'FensterID', 'TempID', 'HumID', 'CO2ID', 'Geraet1ID', 'Geraet2ID', 'Geraet3ID', 'Geraet4ID'],
            'Fahrzeuge' => ['SoCID', 'RangeID', 'ChargingID', 'ChargeMinID', 'ChargePowerID', 'StatusID'],
            'EnergieKacheln' => ['SolarID', 'VerbrauchID', 'NetzID', 'BatterieID'],
            'KlimaGeraete' => ['TempID', 'SollTempID', 'ModusID', 'AktivID', 'VentilID'],
            'Bewaesserung' => ['AktivID', 'NextStartID', 'LaufzeitID', 'BodenID', 'BedarfID', 'TagesRestID'],
            'Lueftungsanlagen' => ['LuefterID', 'LueftModusID', 'FrischluftID', 'ZuluftID', 'BetriebsartID'],
            'Waermepumpen' => ['TempMitteID', 'TempObenID', 'KompressorID', 'HeizstabID'],
        ];

        foreach ($listFields as $listKey => $fields) {
            $items = $this->ReadJsonList($listKey);
            foreach ($items as $index => $item) {
                $context = $this->ConfigurationContext($listKey, $index, $item);
                if (trim((string)($item['Name'] ?? '')) === '') {
                    $errors[] = $context . ': Name darf nicht leer sein. Prüfen: Kachelname ergänzen.';
                }
                $bereich = trim((string)($item['Bereich'] ?? ''));
                if ($bereich !== '' && !in_array($bereich, $bereichNamen, true)) {
                    $errors[] = $context . ': Bereich „' . $bereich . '“ ist nicht definiert. Prüfen: vorhandenen Bereich auswählen oder das Feld leeren.';
                }
                $this->ValidateLinkID($errors, $context . ' → Navigation', $item['LinkID'] ?? 0);
                foreach ($fields as $field) {
                    $this->ValidateVariableID(
                        $errors,
                        $context . ' → ' . $this->FieldLabel($listKey, $field),
                        $item[$field] ?? 0,
                        $this->ExpectedVariableTypes($listKey, $field)
                    );
                }
            }
        }

        $outsideFields = ['AussenTempID', 'AussenTempMinID', 'AussenTempMaxID', 'AussenHumID',
            'WindRichtungID', 'WindBoenID', 'RegenRateID', 'RegenMenge24ID', 'TaupunktID', 'WetterwarnungID', 'UVID'];
        foreach ($outsideFields as $field) {
            $this->ValidateVariableID($errors, $this->ListLabel('Aussen') . ' → ' . $this->FieldLabel('Aussen', $field), $this->ReadPropertyInteger($field));
        }
        $this->ValidateLinkID($errors, $this->ListLabel('Aussen') . ' → Navigation', $this->ReadPropertyInteger('OutdoorLinkID'));

        if ($this->ReadPropertyFloat('TempWarnMin') >= $this->ReadPropertyFloat('TempWarnMax')) {
            $errors[] = 'Temperatur-Warnbereich: Untergrenze muss kleiner als Obergrenze sein';
        }
        if ($this->ReadPropertyFloat('HumidityWarnMin') >= $this->ReadPropertyFloat('HumidityWarnMax')) {
            $errors[] = 'Luftfeuchte-Warnbereich: Untergrenze muss kleiner als Obergrenze sein';
        }
        if ($this->ReadPropertyInteger('CO2WarnLevel') >= $this->ReadPropertyInteger('CO2AlarmLevel')) {
            $errors[] = 'CO₂-Warnbereich: Warnstufe muss kleiner als Alarmstufe sein';
        }
        if ($this->ReadPropertyInteger('SoilWarnLevel') < 0 || $this->ReadPropertyInteger('SoilWarnLevel') > 100) {
            $errors[] = 'Bodenfeuchte-Warnung: Wert muss zwischen 0 und 100 % liegen';
        }
        if ($this->ReadPropertyBoolean('HideTitle') && !function_exists('IPS_SetHiddenTitle')) {
            $errors[] = 'Titelanzeige: Ausblenden wird erst ab IP-Symcon 9.1 unterstützt';
        }

        foreach ($errors as $error) {
            $this->AddConfigurationError($error);
        }
        $this->configurationValidated = true;
        return $this->configurationErrors;
    }

    private function BuildConfigurationWarning(): string
    {
        if ($this->configurationErrors === []) {
            return '';
        }

        $items = array_map(
            fn(string $error): string => '<li>' . $this->EscapeHtml($error) . '</li>',
            array_slice($this->configurationErrors, 0, 12)
        );
        $more = count($this->configurationErrors) > 12
            ? '<li>Weitere Konfigurationsfehler sind vorhanden.</li>'
            : '';
        return '<div class="config-error"><strong>Konfiguration prüfen:</strong><ul>' . implode('', $items) . $more . '</ul></div>';
    }

    private function IsTemperatureAlarm(float $value): bool
    {
        return $value < $this->ReadPropertyFloat('TempWarnMin') || $value > $this->ReadPropertyFloat('TempWarnMax');
    }

    private function IsHumidityAlarm(float $value): bool
    {
        return $value < $this->ReadPropertyFloat('HumidityWarnMin') || $value > $this->ReadPropertyFloat('HumidityWarnMax');
    }

    private function IsCO2Alarm(float $value): bool
    {
        return $value >= $this->ReadPropertyInteger('CO2WarnLevel');
    }

    private function ClampPercent(float $value): int
    {
        return max(0, min(100, (int)round($value)));
    }

    private function FormatDuration(int $seconds): ?string
    {
        if ($seconds < 0) {
            return null;
        }
        $minutes = (int)round($seconds / 60);
        return ($minutes >= 60 ? (int)floor($minutes / 60) . 'h ' : '') . ($minutes % 60) . 'min';
    }

    private function WrapCard(string $html, string $stateClass, int $linkID): string
    {
        if ($linkID <= 0 || !IPS_ObjectExists($linkID)) {
            return $html;
        }
        $needle = "<div class='card{$stateClass}'>";
        $replacement = "<div class='card{$stateClass} clickable' onclick='openObject({$linkID})'>";
        $count = 0;
        $result = str_replace($needle, $replacement, $html, $count);
        return $count > 0 ? $result : $html;
    }

    private function SortByPosition(array &$items): void
    {
        foreach ($items as $i => &$item) {
            if (!isset($item['Position']) || (int)$item['Position'] === 0) {
                $item['_sortKey'] = 10000 + $i;
            } else {
                $item['_sortKey'] = (int)$item['Position'];
            }
        }
        unset($item);
        usort($items, fn($a, $b) => $a['_sortKey'] - $b['_sortKey']);
    }

    private function PrepareContentData(array $bereiche, array $raeume): array
    {
        $fahrzeuge        = $this->ReadJsonList('Fahrzeuge');
        $energieKacheln   = $this->ReadJsonList('EnergieKacheln');
        $klimaGeraete     = $this->ReadJsonList('KlimaGeraete');
        $bewaesserung     = $this->ReadJsonList('Bewaesserung');
        $lueftungsanlagen = $this->ReadJsonList('Lueftungsanlagen');
        $waermepumpen     = $this->ReadJsonList('Waermepumpen');

        $configurationWarning = $this->BuildConfigurationWarning();

        foreach ($raeume          as &$r) { $r['__typ'] = 'raum'; }
        foreach ($fahrzeuge       as &$f) { $f['__typ'] = 'auto'; }
        foreach ($energieKacheln  as &$e) { $e['__typ'] = 'energie'; }
        foreach ($klimaGeraete    as &$k) { $k['__typ'] = 'klima'; }
        foreach ($bewaesserung    as &$b) { $b['__typ'] = 'wasser'; }
        foreach ($lueftungsanlagen as &$l) { $l['__typ'] = 'lueftung'; }
        foreach ($waermepumpen    as &$w) { $w['__typ'] = 'waermepumpe'; }
        unset($r, $f, $e, $k, $b, $l, $w);

        $alleItems = array_merge($raeume, $fahrzeuge, $energieKacheln, $klimaGeraete, $bewaesserung, $lueftungsanlagen, $waermepumpen);

        $this->SortByPosition($bereiche);
        $this->SortByPosition($alleItems);

        foreach ($alleItems as $index => &$item) {
            $item['__cisKey'] = substr(hash('sha256', ($item['__typ'] ?? 'raum') . '|' . $index), 0, 16);
        }
        unset($item);

        // Nur Räume für den globalen Status und HasBereichAlarm
        $nurRaeume = array_filter($alleItems, fn($x) => ($x['__typ'] ?? '') === 'raum');

        // Items nach Bereich gruppieren
        $itemGruppen    = [];
        $itemReihenfolge = [];
        foreach ($alleItems as $item) {
            $bName = trim($item['Bereich'] ?? '');
            if (!isset($itemGruppen[$bName])) {
                $itemReihenfolge[] = $bName;
                $itemGruppen[$bName] = [];
            }
            $itemGruppen[$bName][] = $item;
        }

        // Bereiche-Index
        $bereichIndex = [];
        foreach ($bereiche as $b) {
            $bereichIndex[trim($b['Name'] ?? '')] = $b;
        }

        // Ausgabe-Reihenfolge: konfigurierte Bereiche, dann unkonfigurierte
        $ausgabeReihenfolge = [];
        foreach ($bereiche as $b) {
            $ausgabeReihenfolge[] = trim($b['Name'] ?? '');
        }
        foreach ($itemReihenfolge as $name) {
            if (!in_array($name, $ausgabeReihenfolge, true)) {
                $ausgabeReihenfolge[] = $name;
            }
        }

        return [
            'configurationWarning' => $configurationWarning,
            'hasContent'           => !empty($bereiche) || !empty($alleItems),
            'nurRaeume'            => array_values($nurRaeume),
            'itemGruppen'          => $itemGruppen,
            'bereichIndex'         => $bereichIndex,
            'ausgabeReihenfolge'   => $ausgabeReihenfolge,
        ];
    }

    private function BuildContent(array $bereiche, array $raeume): string
    {
        $data = $this->PrepareContentData($bereiche, $raeume);
        if (!$data['hasContent']) {
            return $data['configurationWarning'] . '<p class="empty">Keine Kacheln konfiguriert.</p>';
        }

        $html  = $data['configurationWarning'];
        $html .= "<div id='cis-outdoor'>" . $this->BuildOutdoorBar() . "</div>";
        $html .= "<div id='cis-global-status'>" . $this->BuildGlobalStatus($data['nurRaeume']) . "</div>";

        foreach ($data['ausgabeReihenfolge'] as $bereichName) {
            $html .= $this->BuildGroup(
                $bereichName,
                $data['bereichIndex'][$bereichName] ?? null,
                $data['itemGruppen'][$bereichName] ?? []
            );
        }

        return $html ?: '<p class="empty">Keine Kacheln konfiguriert.</p>';
    }

    private function BuildGroup(string $bereichName, ?array $bereichDef, array $gruppeItems): string
    {
        if (empty($gruppeItems) && $bereichDef === null) {
            return '';
        }

        $groupKey = $this->BuildDomKey('group', $bereichName);
        $raeumeFuerHeader = array_values(array_filter($gruppeItems, fn($x) => ($x['__typ'] ?? '') === 'raum'));

        $html  = "<div class='grp' id='cis-group-{$groupKey}' data-cis-group='{$groupKey}'>";
        $html .= $this->BuildBereichHeader($bereichName, $bereichDef, $raeumeFuerHeader);

        if (!empty($gruppeItems)) {
            $html .= "<div class='grid'>";
            foreach ($gruppeItems as $item) {
                $html .= $this->BuildRoomCard($item);
            }
            $html .= "</div>";
        }

        return $html . "</div>";
    }

    private function BuildDeltaParts(array $bereiche, array $raeume, array $updatedIDs): array
    {
        $data = $this->PrepareContentData($bereiche, $raeume);
        if (!$data['hasContent']) {
            return [];
        }

        $updateAll = $updatedIDs === [];
        $updatedSet = array_fill_keys($updatedIDs, true);
        $parts = [];

        $outdoorIDs = [];
        foreach ([
            'AussenTempID', 'AussenTempMinID', 'AussenTempMaxID', 'AussenHumID',
            'WindRichtungID', 'WindBoenID', 'RegenRateID', 'RegenMenge24ID',
            'TaupunktID', 'WetterwarnungID', 'UVID',
        ] as $property) {
            $id = $this->ReadPropertyInteger($property);
            if ($id > 0) {
                $outdoorIDs[$id] = true;
            }
        }
        if ($updateAll || $this->HasUpdatedVariable($outdoorIDs, $updatedSet)) {
            $parts['outdoor'] = "<div id='cis-outdoor'>" . $this->BuildOutdoorBar() . "</div>";
        }

        $globalIDs = [];
        foreach ($data['nurRaeume'] as $raum) {
            foreach (['LichtID', 'FensterID', 'TempID', 'HumID', 'CO2ID'] as $field) {
                $id = (int)($raum[$field] ?? 0);
                if ($id > 0) {
                    $globalIDs[$id] = true;
                }
            }
        }
        if ($updateAll || $this->HasUpdatedVariable($globalIDs, $updatedSet)) {
            $parts['globalStatus'] = "<div id='cis-global-status'>" . $this->BuildGlobalStatus($data['nurRaeume']) . "</div>";
        }

        $groups = [];
        foreach ($data['ausgabeReihenfolge'] as $bereichName) {
            $bereichDef = $data['bereichIndex'][$bereichName] ?? null;
            $gruppeItems = $data['itemGruppen'][$bereichName] ?? [];
            $groupIDs = [];

            foreach (['LichtID', 'FensterID', 'RolladenID'] as $field) {
                $id = (int)(($bereichDef ?? [])[$field] ?? 0);
                if ($id > 0) {
                    $groupIDs[$id] = true;
                }
            }
            foreach ($gruppeItems as $item) {
                foreach ($this->GetItemVariableIDs($item) as $id) {
                    $groupIDs[$id] = true;
                }
            }

            if ($updateAll || $this->HasUpdatedVariable($groupIDs, $updatedSet)) {
                $groupKey = $this->BuildDomKey('group', $bereichName);
                $groups[$groupKey] = $this->BuildGroup($bereichName, $bereichDef, $gruppeItems);
            }
        }
        if ($groups !== []) {
            $parts['groups'] = $groups;
        }

        // Unbekannte Sender sollten nicht zu einer dauerhaft veralteten Anzeige führen.
        if (!$updateAll && $updatedIDs !== [] && $parts === []) {
            $parts['outdoor'] = "<div id='cis-outdoor'>" . $this->BuildOutdoorBar() . "</div>";
            $parts['globalStatus'] = "<div id='cis-global-status'>" . $this->BuildGlobalStatus($data['nurRaeume']) . "</div>";
            foreach ($data['ausgabeReihenfolge'] as $bereichName) {
                $groupKey = $this->BuildDomKey('group', $bereichName);
                $parts['groups'][$groupKey] = $this->BuildGroup(
                    $bereichName,
                    $data['bereichIndex'][$bereichName] ?? null,
                    $data['itemGruppen'][$bereichName] ?? []
                );
            }
        }

        return $parts;
    }

    private function HasUpdatedVariable(array $configuredIDs, array $updatedIDs): bool
    {
        foreach ($configuredIDs as $id => $_) {
            if (isset($updatedIDs[$id])) {
                return true;
            }
        }
        return false;
    }

    private function GetItemVariableIDs(array $item): array
    {
        $ids = [];
        foreach ([
            'SoCID', 'RangeID', 'ChargingID', 'ChargeMinID', 'ChargePowerID', 'StatusID',
            'SolarID', 'VerbrauchID', 'NetzID', 'BatterieID',
            'TempID', 'SollTempID', 'ModusID', 'VentilID',
            'AktivID', 'NextStartID', 'LaufzeitID', 'BodenID', 'BedarfID', 'TagesRestID',
            'LuefterID', 'LueftModusID', 'FrischluftID', 'ZuluftID', 'BetriebsartID',
            'TempMitteID', 'TempObenID', 'KompressorID', 'HeizstabID',
        ] as $field) {
            $id = (int)($item[$field] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        return array_keys($ids);
    }

    private function BuildDomKey(string $prefix, string $value): string
    {
        return $prefix . '-' . substr(hash('sha256', $prefix . '|' . $value), 0, 16);
    }

    private function BuildOutdoorBar(): string
    {
        $tempID        = (int)$this->ReadPropertyInteger('AussenTempID');
        $tempMinID     = (int)$this->ReadPropertyInteger('AussenTempMinID');
        $tempMaxID     = (int)$this->ReadPropertyInteger('AussenTempMaxID');
        $humID         = (int)$this->ReadPropertyInteger('AussenHumID');
        $windRichtID   = (int)$this->ReadPropertyInteger('WindRichtungID');
        $windBoenID    = (int)$this->ReadPropertyInteger('WindBoenID');
        $regenRateID   = (int)$this->ReadPropertyInteger('RegenRateID');
        $regen24ID     = (int)$this->ReadPropertyInteger('RegenMenge24ID');
        $warnID        = (int)$this->ReadPropertyInteger('WetterwarnungID');
        $linkID        = (int)$this->ReadPropertyInteger('OutdoorLinkID');

        if ($tempID === 0 || !$this->VariableExistsCached($tempID)) {
            return '';
        }

        $temp    = round((float)$this->GetCachedValue($tempID), 1);
        $tempStr = str_replace('.', ',', (string)$temp) . '°';
        $hum     = null;

        if ($humID > 0 && $this->VariableExistsCached($humID)) {
            $hum = (int)round((float)$this->GetCachedValue($humID));
        }

        if ($temp <= 0)      { $tempCls = 'out-cold'; $icon = '❄️';  $barTheme = 'out-theme-freeze'; }
        elseif ($temp <= 5)  { $tempCls = 'out-cold'; $icon = '🌨️'; $barTheme = 'out-theme-cold'; }
        elseif ($temp <= 10) { $tempCls = 'out-cool'; $icon = '🌥️'; $barTheme = 'out-theme-cool'; }
        elseif ($temp <= 15) { $tempCls = 'out-cool'; $icon = '⛅';  $barTheme = 'out-theme-cool'; }
        elseif ($temp <= 22) { $tempCls = '';          $icon = '🌤️'; $barTheme = 'out-theme-mild'; }
        elseif ($temp <= 28) { $tempCls = 'out-warm'; $icon = '☀️';  $barTheme = 'out-theme-warm'; }
        else                 { $tempCls = 'out-hot';  $icon = '🌡️'; $barTheme = 'out-theme-hot'; }

        $trendIcon = $this->GetTempTrend($tempID);
        $comfort   = $this->OutdoorComfortLabel($temp, $hum);

        $taupunktID = (int)$this->ReadPropertyInteger('TaupunktID');
        $dewPoint   = '';
        if ($taupunktID > 0 && $this->VariableExistsCached($taupunktID)) {
            $dp       = round((float)$this->GetCachedValue($taupunktID), 1);
            $dewPoint = 'Taupunkt ' . str_replace('.', ',', (string)$dp) . '°';
        } elseif ($hum !== null && $temp > 10) {
            $dp       = round($temp - ((100 - $hum) / 5.0), 1);
            $dewPoint = 'Taupunkt ' . str_replace('.', ',', (string)$dp) . '°';
        }

        $minStr = '';
        $maxStr = '';
        if ($tempMinID > 0 && $this->VariableExistsCached($tempMinID)) {
            $minStr = str_replace('.', ',', (string)round((float)$this->GetCachedValue($tempMinID), 1)) . '°';
        }
        if ($tempMaxID > 0 && $this->VariableExistsCached($tempMaxID)) {
            $maxStr = str_replace('.', ',', (string)round((float)$this->GetCachedValue($tempMaxID), 1)) . '°';
        }

        // Wetterwarnung
        $warnBadge = '';
        if ($warnID > 0 && $this->VariableExistsCached($warnID)) {
            $warnLevel = (int)$this->GetCachedValue($warnID);
        $warnText  = $this->EscapeHtml($this->GetCachedFormattedValue($warnID));
            $warnCls   = match(true) {
                $warnLevel === 0                       => 'out-warn-0',
                $warnLevel === 1                       => 'out-warn-1',
                $warnLevel === 2                       => 'out-warn-2',
                $warnLevel === 3                       => 'out-warn-3',
                $warnLevel >= 4 && $warnLevel < 10     => 'out-warn-4',
                $warnLevel === 10                      => 'out-warn-10',
                $warnLevel >= 11                       => 'out-warn-11',
                default                                => 'out-warn-0',
            };
            $warnIcon  = $warnLevel === 0 ? 'fa-shield-halved' : 'fa-triangle-exclamation';
            $warnBadge = "<span class='out-warn {$warnCls}'><i class='fa-solid {$warnIcon}'></i> {$warnText}</span>";
        }

        // UV-Index
        $uvBadge = '';
        $uvID    = (int)$this->ReadPropertyInteger('UVID');
        if ($uvID > 0 && $this->VariableExistsCached($uvID)) {
            $uvVal   = (int)$this->GetCachedValue($uvID);
            $uvCls   = match(true) {
                $uvVal <= 2  => 'uv-low',
                $uvVal <= 5  => 'uv-mid',
                $uvVal <= 7  => 'uv-high',
                $uvVal <= 10 => 'uv-veryhigh',
                default      => 'uv-extreme',
            };
            $uvBadge = "<span class='out-uv {$uvCls}'>UV {$uvVal}</span>";
        }

        // Warn- + UV-Segment zusammenführen
        $warnHtml = '';
        if ($warnBadge !== '' || $uvBadge !== '') {
            $segCls   = ($warnBadge !== '' && $uvBadge !== '') ? ' out-warn-seg' : '';
            $warnHtml = "<div class='out-seg{$segCls}'>{$warnBadge}{$uvBadge}</div>";
        }

        // Klick / Navigation
        $hasLink   = $linkID > 0 && IPS_ObjectExists($linkID);
        $clickAttr = $hasLink ? " onclick='openObject({$linkID})'" : '';
        $clickCls  = $hasLink ? ' clickable' : '';

        $html  = "<div class='out-bar {$barTheme}{$clickCls}'{$clickAttr}>";
        $html .= "<span class='out-icon'>{$icon}</span>";
        $html .= "<div class='out-main'>";
        $html .= "<span class='out-label'>Außen</span>";
        $html .= "<span class='out-temp {$tempCls}'>{$tempStr}{$trendIcon}</span>";
        $html .= "</div>";
        $html .= "<div class='out-seg'><span class='out-comfort'>{$comfort}</span></div>";

        if ($minStr !== '' || $maxStr !== '') {
            $range  = $minStr !== '' ? "<span class='out-lo'>↓{$minStr}</span>" : '';
            $range .= $maxStr !== '' ? "<span class='out-hi'>↑{$maxStr}</span>" : '';
            $html  .= "<div class='out-seg out-range'>{$range}</div>";
        }
        if ($hum !== null) {
            $html .= "<div class='out-seg'><span class='out-hum'>💧 {$hum}%</span></div>";
        }
        if ($dewPoint !== '') {
            $html .= "<div class='out-seg'><span class='out-dew'>{$dewPoint}</span></div>";
        }
        $html .= $warnHtml;

        // Zweite Zeile: Wind & Regen – gleiche Segment-Optik wie Zeile 1
        $row2 = '';
        if ($windRichtID > 0 && $this->VariableExistsCached($windRichtID)) {
            $row2 .= "<div class='out-seg'><i class='fa-solid fa-compass' style='margin-right:3px'></i>" . $this->EscapeHtml($this->GetCachedFormattedValue($windRichtID)) . "</div>";
        }
        if ($windBoenID > 0 && $this->VariableExistsCached($windBoenID)) {
            $row2 .= "<div class='out-seg'><i class='fa-solid fa-wind' style='margin-right:3px'></i>" . $this->EscapeHtml($this->GetCachedFormattedValue($windBoenID)) . "</div>";
        }
        if ($regenRateID > 0 && $this->VariableExistsCached($regenRateID)) {
            $row2 .= "<div class='out-seg'><i class='fa-solid fa-cloud-rain' style='margin-right:3px'></i>" . $this->EscapeHtml($this->GetCachedFormattedValue($regenRateID)) . "</div>";
        }
        if ($regen24ID > 0 && $this->VariableExistsCached($regen24ID)) {
            $row2 .= "<div class='out-seg'><i class='fa-solid fa-cloud-showers-heavy' style='margin-right:3px'></i>24h: " . $this->EscapeHtml($this->GetCachedFormattedValue($regen24ID)) . "</div>";
        }
        if ($row2 !== '') {
            $html .= "<div class='out-row2'>{$row2}</div>";
        }

        $html .= "</div>";
        return $html;
    }

    private function OutdoorComfortLabel(float $temp, ?int $hum): string
    {
        $isHumid = $hum !== null && $hum > 65;
        $isDry   = $hum !== null && $hum < 35;

        if ($temp > 30) { return $isHumid ? '🥵 Drückend'  : '🔆 Sehr heiß'; }
        if ($temp > 25) { return $isHumid ? '😓 Schwül'    : '😎 Heiß'; }
        if ($temp > 20) { return $isDry   ? '😐 Trocken'   : '😊 Warm'; }
        if ($temp > 15) { return '🙂 Angenehm'; }
        if ($temp > 10) { return '🧥 Kühl'; }
        if ($temp > 5)  { return '🥶 Kalt'; }
        if ($temp > 0)  { return '🥶 Sehr kalt'; }
        return '❄️ Gefrierend';
    }

    private function BuildGlobalStatus(array $raeume): string
    {
        $lichterAn    = 0;
        $fensterOffen = 0;
        $tempWarn     = 0;
        $luftWarn     = 0;

        foreach ($raeume as $raum) {
            $lichtID = (int)($raum['LichtID'] ?? 0);
            if ($lichtID > 0 && $this->VariableExistsCached($lichtID)) {
                $on = (bool)$this->GetCachedValue($lichtID);
                if ((bool)($raum['LichtInvert'] ?? false)) $on = !$on;
                if ($on) $lichterAn++;
            }
            $fensterID = (int)($raum['FensterID'] ?? 0);
            if ($fensterID > 0 && $this->VariableExistsCached($fensterID)) {
                $open = (bool)$this->GetCachedValue($fensterID);
                if ((bool)($raum['FensterInvert'] ?? false)) $open = !$open;
                if ($open) $fensterOffen++;
            }
            $tempID = (int)($raum['TempID'] ?? 0);
            if ($tempID > 0 && $this->VariableExistsCached($tempID)) {
                $val = (float)$this->GetCachedValue($tempID);
                if ($this->IsTemperatureAlarm($val)) $tempWarn++;
            }
            $humID = (int)($raum['HumID'] ?? 0);
            if ($humID > 0 && $this->VariableExistsCached($humID)) {
                $val = (int)$this->GetCachedValue($humID);
                if ($this->IsHumidityAlarm($val)) $luftWarn++;
            }
            $co2ID = (int)($raum['CO2ID'] ?? 0);
            if ($co2ID > 0 && $this->VariableExistsCached($co2ID)) {
                $val = (int)$this->GetCachedValue($co2ID);
                if ($this->IsCO2Alarm($val)) $luftWarn++;
            }
        }

        if ($lichterAn === 0 && $fensterOffen === 0 && $tempWarn === 0 && $luftWarn === 0) {
            return "<div class='stat-bar'><span class='stat-ok'><i class='fa-solid fa-check'></i> Alles in Ordnung</span></div>";
        }

        $items = [];
        if ($lichterAn > 0)    { $items[] = "<span class='stat-al al-r'><i class='fa-solid fa-lightbulb'></i> {$lichterAn} an</span>"; }
        if ($fensterOffen > 0) { $items[] = "<span class='stat-al al-r'><i class='fa-solid fa-door-open'></i> {$fensterOffen} offen</span>"; }
        if ($tempWarn > 0)     { $items[] = "<span class='stat-al al-r'><i class='fa-solid fa-temperature-half'></i> {$tempWarn} Temp.</span>"; }
        if ($luftWarn > 0)     { $items[] = "<span class='stat-al al-y'><i class='fa-solid fa-wind'></i> {$luftWarn} Luft</span>"; }

        return "<div class='stat-bar'>" . implode('', $items) . "</div>";
    }

    private function HasBereichAlarm(array $raeume): bool
    {
        foreach ($raeume as $raum) {
            $lichtID = (int)($raum['LichtID'] ?? 0);
            if ($lichtID > 0 && $this->VariableExistsCached($lichtID)) {
                $on = (bool)$this->GetCachedValue($lichtID);
                if ((bool)($raum['LichtInvert'] ?? false)) $on = !$on;
                if ($on) return true;
            }
            $fensterID = (int)($raum['FensterID'] ?? 0);
            if ($fensterID > 0 && $this->VariableExistsCached($fensterID)) {
                $open = (bool)$this->GetCachedValue($fensterID);
                if ((bool)($raum['FensterInvert'] ?? false)) $open = !$open;
                if ($open) return true;
            }
            foreach (['TempID', 'HumID', 'CO2ID'] as $key) {
                $id = (int)($raum[$key] ?? 0);
                if ($id > 0 && $this->VariableExistsCached($id)) {
                    $val = (float)$this->GetCachedValue($id);
                    if ($key === 'TempID' && $this->IsTemperatureAlarm($val)) return true;
                    if ($key === 'HumID'  && $this->IsHumidityAlarm($val))    return true;
                    if ($key === 'CO2ID'  && $this->IsCO2Alarm($val))          return true;
                }
            }
        }
        return false;
    }

    private function BuildBereichHeader(string $name, ?array $def, array $raeume = []): string
    {
        if ($name === '' && $def === null) {
            return '';
        }

        $stats = '';

        if ($def !== null) {
            $lichtID = (int)($def['LichtID'] ?? 0);
            if ($lichtID > 0 && $this->VariableExistsCached($lichtID)) {
                $val     = $this->GetCachedValue($lichtID);
                $varInfo = $this->GetVariableInfoCached($lichtID);
                $varType = $varInfo['VariableType'] ?? 0;
                if ($varType === 0) {
                    $on   = (bool)$val;
                    $cls  = $on ? " class='al-r'" : '';
                    $text = $on ? 'an' : 'aus';
                } else {
                    $on   = $val > 0;
                    $cls  = $on ? " class='al-r'" : '';
                    $text = $on ? "{$val} an" : 'aus';
                }
                $icoL   = $on ? 'ico-on' : 'ico-muted';
                $stats .= "<span class='grp-stat'><i class='fa-solid fa-lightbulb {$icoL}'></i><span{$cls}>{$text}</span></span>";
            }

            $fensterID = (int)($def['FensterID'] ?? 0);
            if ($fensterID > 0 && $this->VariableExistsCached($fensterID)) {
                $val     = $this->GetCachedValue($fensterID);
                $varInfo = $this->GetVariableInfoCached($fensterID);
                $varType = $varInfo['VariableType'] ?? 0;
                if ($varType === 0) {
                    $open = (bool)$val;
                    $cls  = $open ? " class='al-r'" : '';
                    $text = $open ? 'offen' : 'zu';
                } else {
                    $open = $val > 0;
                    $cls  = $open ? " class='al-r'" : '';
                    $text = $open ? "{$val} offen" : 'alle zu';
                }
                $fenIcoH = $open ? 'fa-door-open' : 'fa-door-closed';
                $icoF    = $open ? 'ico-alert' : 'ico-muted';
                $stats  .= "<span class='grp-stat'><i class='fa-solid {$fenIcoH} {$icoF}'></i><span{$cls}>{$text}</span></span>";
            }

            $rolladenID = (int)($def['RolladenID'] ?? 0);
            if ($rolladenID > 0 && $this->VariableExistsCached($rolladenID)) {
                $val     = $this->GetCachedValue($rolladenID);
                $varInfo = $this->GetVariableInfoCached($rolladenID);
                $varType = $varInfo['VariableType'] ?? 0;
                if ($varType === 0) {
                    $cls  = $val ? " class='al-r'" : '';
                    $text = $val ? 'offen' : 'zu';
                } else {
                    $formatted = $this->EscapeHtml($this->GetCachedFormattedValue($rolladenID));
                    $cls  = $val > 0 ? " class='al-r'" : '';
                    $text = $formatted;
                }
                $stats .= "<span class='grp-stat'><i class='fa-solid fa-bars'></i><span{$cls}>{$text}</span></span>";
            }
        }

        if ($stats === '' && !empty($raeume)) {
            $stats = $this->HasBereichAlarm($raeume)
                ? "<span class='grp-alarm'><i class='fa-solid fa-triangle-exclamation'></i> prüfen</span>"
                : "<span class='grp-ok'><i class='fa-solid fa-check'></i> alles ok</span>";
        }

        $linkID      = (int)(($def ?? [])['LinkID'] ?? 0);
        $hasLink     = $linkID > 0 && IPS_ObjectExists($linkID);
        $clickCls    = $hasLink ? ' clickable' : '';
        $clickAttr   = $hasLink ? " onclick='openObject({$linkID})'" : '';
        $displayName = $name !== '' ? $this->EscapeHtml($name) : 'Ohne Bereich';

        return "<div class='grp-hdr{$clickCls}'{$clickAttr}>"
            . "<span class='grp-name'>{$displayName}</span>"
            . ($stats !== '' ? "<span class='grp-chips'>{$stats}</span>" : '')
            . "</div>";
    }

    // Dispatch-Funktion – leitet nach Typ weiter
    private function BuildRoomCard(array $item): string
    {
        $html = match($item['__typ'] ?? 'raum') {
            'auto'        => $this->BuildCard_Auto($item),
            'energie'     => $this->BuildCard_Energie($item),
            'klima'       => $this->BuildCard_Klima($item),
            'wasser'      => $this->BuildCard_Wasser($item),
            'lueftung'    => $this->BuildCard_Lueftung($item),
            'waermepumpe' => $this->BuildCard_Waermepumpe($item),
            default       => $this->BuildCard_Raum($item),
        };
        $key = (string)($item['__cisKey'] ?? '');
        if ($key === '') {
            return $html;
        }
        return preg_replace(
            "/^<div class='card/",
            "<div id='cis-card-{$key}' data-cis-card='{$key}' class='card",
            $html,
            1
        ) ?? $html;
    }

    // ── Raum-Kachel ────────────────────────────────────────────────────────────────────

    private function BuildCard_Raum(array $raum): string
    {
        $name = $this->EscapeHtml($raum['Name'] ?? 'Unbenannt');

        // Temperatur + Trend
        $tempStr   = '';
        $tempCls   = '';
        $trendIcon = '';
        $tempID    = (int)($raum['TempID'] ?? 0);
        if ($tempID > 0 && $this->VariableExistsCached($tempID)) {
            $val       = round((float)$this->GetCachedValue($tempID), 1);
            $tempCls   = $this->IsTemperatureAlarm($val) ? ' al-r' : '';
            $tempStr   = str_replace('.', ',', (string)$val) . '°';
            $trendIcon = $this->GetTempTrend($tempID);
        }

        // Licht
        $lichtID   = (int)($raum['LichtID'] ?? 0);
        $isLichtAn = false;
        $lichtHTML = '';
        if ($lichtID > 0 && $this->VariableExistsCached($lichtID)) {
            $on = (bool)$this->GetCachedValue($lichtID);
            if ((bool)($raum['LichtInvert'] ?? false)) $on = !$on;
            $isLichtAn = $on;
            $cls       = $on ? " class='al-r'" : '';
            $text      = $on ? 'an' : 'aus';
            $icoLicht  = $on ? 'ico-on' : 'ico-muted';
            $lichtHTML = "<span class='p-ico'><i class='fa-solid fa-lightbulb {$icoLicht}'></i></span><span{$cls}>{$text}</span>";
        }

        // Fenster
        $fensterID    = (int)($raum['FensterID'] ?? 0);
        $isFensterAuf = false;
        $fensterHTML  = '';
        if ($fensterID > 0 && $this->VariableExistsCached($fensterID)) {
            $open = (bool)$this->GetCachedValue($fensterID);
            if ((bool)($raum['FensterInvert'] ?? false)) $open = !$open;
            $isFensterAuf = $open;
            $cls          = $open ? " class='al-r'" : '';
            $text         = $open ? 'offen' : 'zu';
            $fenIco       = $open ? 'fa-door-open' : 'fa-door-closed';
            $icoFen       = $open ? 'ico-alert' : 'ico-muted';
            $fensterHTML  = "<span class='p-ico'><i class='fa-solid {$fenIco} {$icoFen}'></i></span><span{$cls}>{$text}</span>";
        }

        // Luftfeuchtigkeit
        $humID   = (int)($raum['HumID'] ?? 0);
        $humHTML = '';
        if ($humID > 0 && $this->VariableExistsCached($humID)) {
            $val     = (int)round((float)$this->GetCachedValue($humID));
            $alarm   = $this->IsHumidityAlarm($val);
            $cls     = $alarm ? " class='al-r'" : '';
            $icoHum  = $alarm ? 'ico-alert' : 'ico-muted';
            $humHTML = "<span class='p-ico'><i class='fa-solid fa-droplet {$icoHum}'></i></span><span{$cls}>{$val}%</span>";
        }

        // CO₂
        $co2ID   = (int)($raum['CO2ID'] ?? 0);
        $co2HTML = '';
        if ($co2ID > 0 && $this->VariableExistsCached($co2ID)) {
            $val = (int)$this->GetCachedValue($co2ID);
            if ($val > $this->ReadPropertyInteger('CO2AlarmLevel')) { $valCls = " class='al-r'"; $dotCls = 'dot-r'; $icoCO2 = 'ico-alert'; }
            elseif ($val >= $this->ReadPropertyInteger('CO2WarnLevel')) { $valCls = " class='al-y'"; $dotCls = 'dot-y'; $icoCO2 = 'ico-warn'; }
            else                  { $valCls = '';               $dotCls = 'dot-g'; $icoCO2 = 'ico-muted'; }
            $co2HTML = "<span class='p-ico'><i class='fa-solid fa-wind {$icoCO2}'></i></span><span{$valCls}>{$val}</span><span class='co2dot {$dotCls}'></span>";
        }

        // Slot-Pool
        $slotPool = [
            'licht'   => $lichtHTML,
            'fenster' => $fensterHTML,
            'hum'     => $humHTML,
            'co2'     => $co2HTML,
            'temp'    => $tempStr !== '' ? "<span class='p-ico'><i class='fa-solid fa-temperature-half ico-muted'></i></span><span class='{$tempCls}'>{$tempStr}{$trendIcon}</span>" : '',
            'geraet1' => $this->RenderGeraet(1, $raum),
            'geraet2' => $this->RenderGeraet(2, $raum),
            'geraet3' => $this->RenderGeraet(3, $raum),
            'geraet4' => $this->RenderGeraet(4, $raum),
        ];

        // Slots lesen (Defaults: licht, fenster, hum, co2)
        $slots = [
            $raum['Slot1'] ?? 'licht',
            $raum['Slot2'] ?? 'fenster',
            $raum['Slot3'] ?? 'hum',
            $raum['Slot4'] ?? 'co2',
        ];

        $row1Cells = [$slotPool[$slots[0]] ?? '', $slotPool[$slots[1]] ?? ''];
        $row2Cells = [$slotPool[$slots[2]] ?? '', $slotPool[$slots[3]] ?? ''];
        $row1 = ($row1Cells[0] !== '' || $row1Cells[1] !== '')
            ? "<div class='p-row'><span class='p-cell'>{$row1Cells[0]}</span><span class='p-cell'>{$row1Cells[1]}</span></div>"
            : '';
        $row2 = ($row2Cells[0] !== '' || $row2Cells[1] !== '')
            ? "<div class='p-row'><span class='p-cell'>{$row2Cells[0]}</span><span class='p-cell'>{$row2Cells[1]}</span></div>"
            : '';

        if ($isFensterAuf)  { $stateClass = ' s-alert'; }
        elseif ($isLichtAn) { $stateClass = ' s-warn'; }
        else                { $stateClass = ''; }

        $head = "<div class='c-head'><span class='c-name'>{$name}</span>"
            . ($tempStr !== '' ? "<span class='c-temp{$tempCls}'>{$tempStr}{$trendIcon}</span>" : '')
            . "</div>";

        $linkID   = (int)($raum['LinkID'] ?? 0);
        $hasLink  = $linkID > 0 && IPS_ObjectExists($linkID);
        $cardAttr = $hasLink
            ? "class='card{$stateClass} clickable' onclick='openObject({$linkID})'"
            : "class='card{$stateClass}'";

        return "<div {$cardAttr}>{$head}{$row1}{$row2}</div>";
    }

    private function GetTempTrend(int $varID): string
    {
        if ($varID <= 0 || !$this->VariableExistsCached($varID)) {
            return '';
        }

        $now = time();
        if (isset($this->trendCache[$varID])
            && $this->trendCache[$varID]['expires'] > $now) {
            return $this->trendCache[$varID]['html'];
        }

        if ($this->archiveID === null || $this->archiveID === 0) {
            $ids       = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
            $this->archiveID = !empty($ids) ? (int)$ids[0] : 0;
        }
        if ($this->archiveID === 0) {
            return '';
        }
        if (!AC_GetLoggingStatus($this->archiveID, $varID)) {
            $this->trendCache[$varID] = [
                'expires' => $now + self::TREND_CACHE_TTL_SECONDS,
                'html'    => '',
            ];
            return '';
        }

        $values = AC_GetLoggedValues($this->archiveID, $varID, $now - self::TREND_PRIMARY_SECONDS, $now, 0);
        $thresh = self::TREND_PRIMARY_THRESHOLD;

        // Sensor loggt nur bei Änderung → 2-Std.-Fenster zu leer → 6-Std.-Fenster probieren
        if (!is_array($values) || count($values) < 2) {
            $values = AC_GetLoggedValues($this->archiveID, $varID, $now - self::TREND_FALLBACK_SECONDS, $now, 0);
            $thresh = self::TREND_FALLBACK_THRESHOLD;
        }

        // Immer noch < 2 Werte → Temperatur ist konstant stabil
        if (!is_array($values) || count($values) < 2) {
            $trend = " <i class='fa-solid fa-arrow-right trend-st'></i>";
            $this->trendCache[$varID] = [
                'expires' => $now + self::TREND_CACHE_TTL_SECONDS,
                'html'    => $trend,
            ];
            return $trend;
        }

        // AC_GetLoggedValues liefert newest-first: Index 0 = neuster, letzter = ältester
        $newest = (float)$values[0]['Value'];
        $oldest = (float)$values[count($values) - 1]['Value'];
        $delta  = $newest - $oldest;

        if ($delta >= $thresh) {
            $trend = " <i class='fa-solid fa-arrow-trend-up trend-up'></i>";
            $this->trendCache[$varID] = [
                'expires' => $now + self::TREND_CACHE_TTL_SECONDS,
                'html'    => $trend,
            ];
            return $trend;
        }
        if ($delta <= -$thresh) {
            $trend = " <i class='fa-solid fa-arrow-trend-down trend-dn'></i>";
            $this->trendCache[$varID] = [
                'expires' => $now + self::TREND_CACHE_TTL_SECONDS,
                'html'    => $trend,
            ];
            return $trend;
        }
        $trend = " <i class='fa-solid fa-arrow-right trend-st'></i>";
        $this->trendCache[$varID] = [
            'expires' => $now + self::TREND_CACHE_TTL_SECONDS,
            'html'    => $trend,
        ];
        return $trend;
    }

    private function RenderGeraet(int $nr, array $raum): string
    {
        $id    = (int)($raum["Geraet{$nr}ID"] ?? 0);
        $label = $this->EscapeHtml($raum["Geraet{$nr}Name"] ?? "Gerät {$nr}");
        if ($id === 0 || !$this->VariableExistsCached($id)) {
            return '';
        }
        $on  = (bool)$this->GetCachedValue($id);
        $cls = $on ? " class='al-r'" : '';
        $ico = $on ? 'ico-on' : 'ico-muted';
        return "<span class='p-ico'><i class='fa-solid fa-plug {$ico}'></i></span><span{$cls}>{$label}</span>";
    }

    // ── E-Auto-Kachel ─────────────────────────────────────────────────────────────────────

    private function BuildCard_Auto(array $item): string
    {
        $name = $this->EscapeHtml($item['Name'] ?? '');

        // SoC
        $socID  = (int)($item['SoCID'] ?? 0);
        $soc    = null;
        $socStr = '';
        $socCls = '';
        if ($socID > 0 && $this->VariableExistsCached($socID)) {
            $soc    = $this->ClampPercent((float)$this->GetCachedValue($socID));
            $socCls = $soc < 20 ? ' al-r' : ($soc < 40 ? ' al-y' : '');
            $socStr = "{$soc}%";
        }

        // Reichweite
        $rangeID  = (int)($item['RangeID'] ?? 0);
        $rangeStr = ($rangeID > 0 && $this->VariableExistsCached($rangeID))
            ? (int)$this->GetCachedValue($rangeID) . ' km' : '';

        // Lädt?
        $chargingID = (int)($item['ChargingID'] ?? 0);
        $isCharging = $chargingID > 0 && $this->VariableExistsCached($chargingID)
            && (bool)$this->GetCachedValue($chargingID);

        // Restladezeit (Wert in Sekunden)
        $chargeMin   = '';
        $chargeMinID = (int)($item['ChargeMinID'] ?? 0);
        if ($isCharging && $chargeMinID > 0 && $this->VariableExistsCached($chargeMinID)) {
            $chargeMin = $this->FormatDuration((int)$this->GetCachedValue($chargeMinID)) ?? '';
        }

        // Ladeleistung kW
        $chargePowerStr  = '';
        $chargePowerID   = (int)($item['ChargePowerID'] ?? 0);
        if ($isCharging && $chargePowerID > 0 && $this->VariableExistsCached($chargePowerID)) {
            $watts          = (float)$this->GetCachedValue($chargePowerID);
            $kw             = round($watts / 1000, 1);
            $chargePowerStr = str_replace('.', ',', (string)$kw) . ' kW';
        }

        // Status
        $statusStr = '';
        $statusID  = (int)($item['StatusID'] ?? 0);
        if ($statusID > 0 && $this->VariableExistsCached($statusID)) {
            $statusStr = $this->EscapeHtml($this->GetCachedFormattedValue($statusID));
        }

        if ($isCharging)         { $stateClass = ' s-charging'; }
        elseif (!empty($socCls)) { $stateClass = ' s-warn'; }
        else                     { $stateClass = ''; }

        $html  = "<div class='card{$stateClass}'>";
        $html .= "<div class='c-head'>";
        $html .= "<span class='c-name'>{$name}</span>";
        if ($socStr) {
            $html .= "<span class='c-temp{$socCls}'>🔋 {$socStr}</span>";
        }
        $html .= "</div>";

        if ($soc !== null) {
            $fillCls = $soc < 20 ? 'soc-crit' : ($soc < 40 ? 'soc-low' : 'soc-ok');
            $html   .= "<div class='soc-track'><div class='soc-fill {$fillCls}' style='width:{$soc}%'></div></div>";
        }

        if ($statusStr) {
            $html .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-car ico-muted'></i></span><span>{$statusStr}</span></span></div>";
        }
        if ($isCharging) {
            $parts     = array_filter([$chargePowerStr, $chargeMin]);
            $chargeTxt = $parts ? implode(' · ', $parts) : 'Lädt…';
            $html .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-bolt ico-charging'></i></span><span>{$chargeTxt}</span></span></div>";
        }
        if ($rangeStr) {
            $html .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-road ico-muted'></i></span><span>{$rangeStr}</span></span></div>";
        }

        $linkID = (int)($item['LinkID'] ?? 0);
        $html = $this->WrapCard($html, $stateClass, $linkID);

        $html .= "</div>";
        return $html;
    }

    // ── Energie/Solar-Kachel ──────────────────────────────────────────────────────

    private function BuildCard_Energie(array $item): string
    {
        $name = $this->EscapeHtml($item['Name'] ?? '');

        $solarID     = (int)($item['SolarID']     ?? 0);
        $verbrauchID = (int)($item['VerbrauchID'] ?? 0);
        $netzID      = (int)($item['NetzID']      ?? 0);
        $batterieID  = (int)($item['BatterieID']  ?? 0);

        $solarW  = ($solarID > 0     && $this->VariableExistsCached($solarID))     ? (int)$this->GetCachedValue($solarID)     : null;
        $verbW   = ($verbrauchID > 0 && $this->VariableExistsCached($verbrauchID)) ? (int)$this->GetCachedValue($verbrauchID) : null;
        $netzW   = ($netzID > 0      && $this->VariableExistsCached($netzID))      ? (int)$this->GetCachedValue($netzID)      : null;
            $batPct  = ($batterieID > 0  && $this->VariableExistsCached($batterieID))  ? $this->ClampPercent((float)$this->GetCachedValue($batterieID))  : null;

        $html  = "<div class='card'>";
        $html .= "<div class='c-head'><span class='c-name'>{$name}</span>";
        if ($solarW !== null) {
            $html .= "<span class='c-temp'><i class='fa-solid fa-sun ico-solar'></i> {$solarW} W</span>";
        }
        $html .= "</div>";

        if ($verbW !== null) {
            $html .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-house ico-muted'></i></span><span>{$verbW} W</span></span></div>";
        }
        if ($netzW !== null) {
            if ($netzW >= 0) {
                $netzCls = 'ico-grid-out'; // Bezug = rot
                $netzTxt = "+{$netzW} W";
            } else {
                $netzCls = 'ico-grid-in';  // Einspeisung = grün
                $netzTxt = "{$netzW} W";
            }
            $html .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-plug-circle-bolt {$netzCls}'></i></span><span>{$netzTxt}</span></span></div>";
        }
        if ($batPct !== null) {
            $fillCls = $batPct < 20 ? 'soc-crit' : ($batPct < 40 ? 'soc-low' : 'soc-ok');
            $html   .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-battery-half ico-muted'></i></span><span>{$batPct}%</span></span></div>";
            $html   .= "<div class='soc-track'><div class='soc-fill {$fillCls}' style='width:{$batPct}%'></div></div>";
        }

        $linkID = (int)($item['LinkID'] ?? 0);
        $html = $this->WrapCard($html, '', $linkID);

        $html .= "</div>";
        return $html;
    }

    // ── Klima/Thermostat-Kachel ─────────────────────────────────────────────────────

    private function BuildCard_Klima(array $item): string
    {
        $name = $this->EscapeHtml($item['Name'] ?? '');

        $tempID     = (int)($item['TempID']     ?? 0);
        $sollTempID = (int)($item['SollTempID'] ?? 0);
        $modusID    = (int)($item['ModusID']    ?? 0);
        $aktivID    = (int)($item['AktivID']    ?? 0);
        $ventilID   = (int)($item['VentilID']   ?? 0);

        $istTemp  = ($tempID > 0     && $this->VariableExistsCached($tempID))     ? round((float)$this->GetCachedValue($tempID), 1)     : null;
        $sollTemp = ($sollTempID > 0 && $this->VariableExistsCached($sollTempID)) ? round((float)$this->GetCachedValue($sollTempID), 1) : null;
        $modus    = ($modusID > 0    && $this->VariableExistsCached($modusID))    ? $this->EscapeHtml($this->GetCachedFormattedValue($modusID)) : null;
        $istAktiv = ($aktivID > 0    && $this->VariableExistsCached($aktivID))    ? (bool)$this->GetCachedValue($aktivID) : null;

        $lueftermodus = ($ventilID > 0 && $this->VariableExistsCached($ventilID))
            ? $this->EscapeHtml($this->GetCachedFormattedValue($ventilID)) : null;

        $trendIcon = ($tempID > 0 && $this->VariableExistsCached($tempID)) ? $this->GetTempTrend($tempID) : '';
        $istStr  = $istTemp  !== null ? str_replace('.', ',', (string)$istTemp)  . '°' : '';
        $sollStr = $sollTemp !== null ? str_replace('.', ',', (string)$sollTemp) . '°' : '';

        // Randfarbe abhängig von Betriebsmodus UND An/Aus-Zustand
        $stateClass = '';
        if ($istAktiv === false) {
            $stateClass = ' s-inactive';
        } elseif ($modus !== null) {
            $modusLower = mb_strtolower($modus);
            if (str_contains($modusLower, 'kühl'))        { $stateClass = ' s-charging'; }
            elseif (str_contains($modusLower, 'heiz'))    { $stateClass = ' s-alert'; }
            elseif (str_contains($modusLower, 'entfeuc'))  { $stateClass = ' s-dehumid'; }
            // 'lüften' und 'automatik' → kein Rand (transparent)
        } elseif ($istTemp !== null && $sollTemp !== null && $istTemp < $sollTemp - 1) {
            $stateClass = ' s-warn';
        }

        $html  = "<div class='card{$stateClass}'>";
        $html .= "<div class='c-head'><span class='c-name'>{$name}</span>";
        if ($istStr) {
            $html .= "<span class='c-temp'><i class='fa-solid fa-temperature-half ico-muted'></i> {$istStr}{$trendIcon}</span>";
        }
        $html .= "</div>";

        if ($sollStr) {
            $html .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-sliders ico-muted'></i></span><span>Soll: {$sollStr}</span></span></div>";
        }
        if ($modus) {
            $html .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-circle-half-stroke ico-muted'></i></span><span>{$modus}</span></span></div>";
        }
        if ($lueftermodus !== null && $lueftermodus !== '') {
            $html .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-fan ico-muted'></i></span><span>{$lueftermodus}</span></span></div>";
        }

        $linkID = (int)($item['LinkID'] ?? 0);
        $html = $this->WrapCard($html, $stateClass, $linkID);

        $html .= "</div>";
        return $html;
    }

    // ── Bewässerungs-Kachel ─────────────────────────────────────────────────────────

    private function BuildCard_Wasser(array $item): string
    {
        $name = $this->EscapeHtml($item['Name'] ?? '');

        $aktivID     = (int)($item['AktivID']     ?? 0);
        $nextStartID = (int)($item['NextStartID'] ?? 0);
        $laufzeitID  = (int)($item['LaufzeitID']  ?? 0);
        $bodenID     = (int)($item['BodenID']     ?? 0);
        $bedarfID    = (int)($item['BedarfID']    ?? 0);
        $tagesRestID = (int)($item['TagesRestID'] ?? 0);

        $isAktiv   = ($aktivID > 0     && $this->VariableExistsCached($aktivID))     && (bool)$this->GetCachedValue($aktivID);
        $nextStr   = ($nextStartID > 0 && $this->VariableExistsCached($nextStartID)) ? $this->EscapeHtml($this->GetCachedFormattedValue($nextStartID)) : null;
        $laufzeit  = ($laufzeitID > 0  && $this->VariableExistsCached($laufzeitID))  ? (int)$this->GetCachedValue($laufzeitID)  : null;
        $boden     = ($bodenID > 0     && $this->VariableExistsCached($bodenID))     ? (int)$this->GetCachedValue($bodenID)     : null;
        if ($bedarfID > 0 && $this->VariableExistsCached($bedarfID)) {
            $bedarfStr = $this->FormatDuration((int)$this->GetCachedValue($bedarfID));
        } else {
            $bedarfStr = null;
        }
        $tagesRest = ($tagesRestID > 0 && $this->VariableExistsCached($tagesRestID)) ? (int)$this->GetCachedValue($tagesRestID) : null;

        $stateClass = $isAktiv ? ' s-active' : '';

        $html  = "<div class='card{$stateClass}'>";
        $html .= "<div class='c-head'><span class='c-name'>{$name}</span>";
        if ($isAktiv) {
            $html .= "<span class='c-temp al-g'><i class='fa-solid fa-droplet'></i> aktiv</span>";
        }
        $html .= "</div>";

        if ($isAktiv && $laufzeit !== null && $laufzeit > 0) {
            $restStr = $this->FormatDuration($laufzeit);
            $html   .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-clock ico-active'></i></span><span>noch {$restStr}</span></span></div>";
        }
        if ($tagesRest !== null) {
            $tagesStr = $this->FormatDuration($tagesRest);
            $html    .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-hourglass-half ico-muted'></i></span><span>Heute noch: {$tagesStr}</span></span></div>";
        }
        if ($bedarfStr !== null) {
            $html .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-chart-simple ico-muted'></i></span><span>Bedarf: {$bedarfStr}</span></span></div>";
        }
        if ($nextStr) {
            $html .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-calendar ico-muted'></i></span><span>{$nextStr}</span></span></div>";
        }
        if ($boden !== null) {
            $boden = $this->ClampPercent((float)$boden);
            $bodenCls = $boden < $this->ReadPropertyInteger('SoilWarnLevel') ? 'ico-warn' : 'ico-muted';
            $html    .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-seedling {$bodenCls}'></i></span><span>Boden: {$boden}%</span></span></div>";
        }

        $linkID = (int)($item['LinkID'] ?? 0);
        $html = $this->WrapCard($html, $stateClass, $linkID);

        $html .= "</div>";
        return $html;
    }

    // ── Lüftungsanlage-Kachel ─────────────────────────────────────────────────

    private function BuildCard_Lueftung(array $item): string
    {
        $name = $this->EscapeHtml($item['Name'] ?? '');

        $luefterID     = (int)($item['LuefterID']     ?? 0);
        $lueftModusID  = (int)($item['LueftModusID']  ?? 0);
        $frischluftID  = (int)($item['FrischluftID']  ?? 0);
        $zuluftID      = (int)($item['ZuluftID']      ?? 0);
        $betriebsartID = (int)($item['BetriebsartID'] ?? 0);

        $luefterDisplay = null;
        $isActive = false;
        if ($luefterID > 0 && $this->VariableExistsCached($luefterID)) {
            $rawLuefter = $this->GetCachedValue($luefterID);
            $luefterDisplay = $this->EscapeHtml($this->GetCachedFormattedValue($luefterID));
            if (is_numeric($rawLuefter)) {
                $isActive = (float)$rawLuefter > 0;
            } else {
                $isActive = !in_array(strtolower(trim((string)$rawLuefter)), ['', '0', 'aus', 'off', 'false', 'inaktiv'], true);
            }
        }
        $modus       = ($lueftModusID > 0  && $this->VariableExistsCached($lueftModusID))  ? $this->EscapeHtml($this->GetCachedFormattedValue($lueftModusID))           : null;
        $frischluft  = ($frischluftID > 0  && $this->VariableExistsCached($frischluftID))  ? round((float)$this->GetCachedValue($frischluftID), 1)                     : null;
        $zuluft      = ($zuluftID > 0      && $this->VariableExistsCached($zuluftID))      ? round((float)$this->GetCachedValue($zuluftID), 1)                         : null;
        $betriebsart = ($betriebsartID > 0 && $this->VariableExistsCached($betriebsartID)) ? $this->EscapeHtml($this->GetCachedFormattedValue($betriebsartID))          : null;

        $stateClass = $isActive ? ' s-active' : '';
        $fanCls     = $isActive ? 'ico-active' : 'ico-muted';

        $html  = "<div class='card{$stateClass}'>";
        $html .= "<div class='c-head'><span class='c-name'>{$name}</span>";
        if ($luefterDisplay !== null) {
            $html .= "<span class='c-temp'><i class='fa-solid fa-fan {$fanCls}'></i> Stufe {$luefterDisplay}</span>";
        }
        $html .= "</div>";

        if ($modus) {
            $html .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-circle-half-stroke ico-muted'></i></span><span>{$modus}</span></span></div>";
        }
        if ($betriebsart) {
            $html .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-sliders ico-muted'></i></span><span>{$betriebsart}</span></span></div>";
        }
        if ($frischluft !== null || $zuluft !== null) {
            $frStr = $frischluft !== null ? str_replace('.', ',', (string)$frischluft) . '°' : '–';
            $zuStr = $zuluft     !== null ? str_replace('.', ',', (string)$zuluft)     . '°' : '–';
            $html .= "<div class='p-row'>"
                . "<span class='p-cell'><span class='p-ico'><i class='fa-solid fa-arrow-right-to-bracket ico-muted'></i></span><span>{$frStr}</span></span>"
                . "<span class='p-cell'><span class='p-ico'><i class='fa-solid fa-arrow-right-from-bracket ico-muted'></i></span><span>{$zuStr}</span></span>"
                . "</div>";
        }

        $linkID = (int)($item['LinkID'] ?? 0);
        $html = $this->WrapCard($html, $stateClass, $linkID);

        $html .= "</div>";
        return $html;
    }

    // ── Warmwasser-Wärmepumpe-Kachel ──────────────────────────────────────────

    private function BuildCard_Waermepumpe(array $item): string
    {
        $name = $this->EscapeHtml($item['Name'] ?? '');

        $tempMitteID  = (int)($item['TempMitteID']  ?? 0);
        $tempObenID   = (int)($item['TempObenID']   ?? 0);
        $kompressorID = (int)($item['KompressorID'] ?? 0);
        $heizstabID   = (int)($item['HeizstabID']   ?? 0);

        $tempMitte  = ($tempMitteID > 0  && $this->VariableExistsCached($tempMitteID))  ? round((float)$this->GetCachedValue($tempMitteID), 1)                    : null;
        $tempOben   = ($tempObenID > 0   && $this->VariableExistsCached($tempObenID))   ? round((float)$this->GetCachedValue($tempObenID), 1)                     : null;
        $kompStr    = ($kompressorID > 0 && $this->VariableExistsCached($kompressorID)) ? $this->EscapeHtml($this->GetCachedFormattedValue($kompressorID))          : null;
        $heizStr    = ($heizstabID > 0   && $this->VariableExistsCached($heizstabID))   ? $this->EscapeHtml($this->GetCachedFormattedValue($heizstabID))            : null;
        $isKompAn   = ($kompressorID > 0 && $this->VariableExistsCached($kompressorID)) && (bool)$this->GetCachedValue($kompressorID);
        $isHzAn     = ($heizstabID > 0   && $this->VariableExistsCached($heizstabID))   && (bool)$this->GetCachedValue($heizstabID);

        if ($isKompAn)   { $stateClass = ' s-charging'; }  // blau = Kompressor aktiv
        elseif ($isHzAn) { $stateClass = ' s-warn'; }      // orange = Heizstab aktiv
        else             { $stateClass = ''; }

        $tempObenStr  = $tempOben  !== null ? str_replace('.', ',', (string)$tempOben)  . '°' : '';
        $tempMitteStr = $tempMitte !== null ? str_replace('.', ',', (string)$tempMitte) . '°' : '';

        $html  = "<div class='card{$stateClass}'>";
        $html .= "<div class='c-head'><span class='c-name'>{$name}</span>";
        if ($tempObenStr) {
            $html .= "<span class='c-temp'><i class='fa-solid fa-temperature-high ico-muted'></i> {$tempObenStr}</span>";
        }
        $html .= "</div>";

        if ($tempMitteStr) {
            $html .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-temperature-half ico-muted'></i></span><span>Mitte: {$tempMitteStr}</span></span></div>";
        }
        if ($kompStr !== null) {
            $kCls = $isKompAn ? 'ico-charging' : 'ico-muted';
            $kTxt = $isKompAn ? " class='ico-charging'" : '';
            $html .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-gear {$kCls}'></i></span><span{$kTxt}>{$kompStr}</span></span></div>";
        }
        if ($heizStr !== null) {
            $hCls = $isHzAn ? 'ico-warn' : 'ico-muted';
            $hTxt = $isHzAn ? " class='al-y'" : '';
            $html .= "<div class='p-row'><span class='p-cell'><span class='p-ico'><i class='fa-solid fa-bolt {$hCls}'></i></span><span{$hTxt}>{$heizStr}</span></span></div>";
        }

        $linkID = (int)($item['LinkID'] ?? 0);
        $html = $this->WrapCard($html, $stateClass, $linkID);

        $html .= "</div>";
        return $html;
    }
}
