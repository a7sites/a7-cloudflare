<?php
/*
 * Plugin Name:       A7 CloudFlare Analitics
 * Plugin URI:        https://dash.a7site.com.br/
 * Description:       Plugin WordPress que exibe métricas e gráficos de desempenho do Cloudflare diretamente no painel administrativo, facilitando o monitoramento do tráfego e da segurança do seu site.
 * Version:           3.4.1
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Author:            Alexandro F. S. Martins
 * Author URI:        https://a7site.com.br/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://dash.a7site.com.br/
 * Text Domain:       plugin de conexão com cloudflare
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) exit;

class A7_CloudFlare {

	private $option_name = 'a7_cf_settings';

	public function __construct() {
		add_action('admin_menu', [$this, 'add_admin_menu']);
		add_action('admin_init', [$this, 'settings_init']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
		add_action('rest_api_init', function() {
			register_rest_route('a7cloudflare/v1', '/geoip', [
				'methods' => 'GET',
				'callback' => function(WP_REST_Request $request) {
					if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
						$ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
					} elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
						$ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
					} else {
						$ip = $_SERVER['REMOTE_ADDR'];
					}
					if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
						return new WP_Error('invalid_ip', 'IP inválido', ['status' => 400]);
					}
					$geo = @file_get_contents("http://ip-api.com/json/" . urlencode($ip) . "?fields=status,message,query,country,regionName,city,lat,lon,isp");
					$data = json_decode($geo, true);
					if (!$data || $data['status'] !== 'success') {
						return new WP_Error('geo_fail', 'Falha na consulta de geolocalização', ['status' => 500]);
					}
					// Salva no arquivo (wp-content/uploads/a7cloudflare_visitors.json)
					$upload_dir = wp_upload_dir();
					$file = trailingslashit($upload_dir['basedir']) . 'a7cloudflare_visitors.json';
					$all = [];
					if (file_exists($file)) {
						$all = json_decode(file_get_contents($file), true) ?: [];
					}
					$all[] = [
						'ip' => $ip,
						'lat' => $data['lat'],
						'lon' => $data['lon'],
						'city' => $data['city'],
						'country' => $data['country'],
						'isp' => $data['isp'],
						'time' => date('c')
					];
					file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT));
					return [
						'ip' => $ip,
						'lat' => $data['lat'],
						'lon' => $data['lon'],
						'city' => $data['city'],
						'country' => $data['country'],
						'isp' => $data['isp']
					];
				},
				'permission_callback' => '__return_true',
			]);
			// Endpoint para listar todos os visitantes
			register_rest_route('a7cloudflare/v1', '/visitors', [
				'methods' => 'GET',
				'callback' => function(WP_REST_Request $request) {
					$upload_dir = wp_upload_dir();
					$file = trailingslashit($upload_dir['basedir']) . 'a7cloudflare_visitors.json';
					if (!file_exists($file)) return [];
					$all = json_decode(file_get_contents($file), true) ?: [];
					return $all;
				},
				'permission_callback' => '__return_true',
			]);
		});
	}

	public function add_admin_menu() {
		add_menu_page('A7 CloudFlare', 'A7 CloudFlare', 'manage_options', 'a7_cloudflare_metrics', [$this, 'metrics_page'], 'dashicons-chart-line', 3);
		add_submenu_page('a7_cloudflare_metrics', 'Configurações', 'Configurações', 'manage_options', 'a7_cloudflare_settings', [$this, 'settings_page']);
	}

	public function settings_init() {
		register_setting('a7_cf_group', $this->option_name);
		add_settings_section('a7_cf_section', 'Configurações da API Cloudflare', null, 'a7_cloudflare_settings');
		add_settings_field('api_token', 'API Token', [$this, 'api_token_render'], 'a7_cloudflare_settings', 'a7_cf_section');
		add_settings_field('zone_id', 'Zone ID', [$this, 'zone_id_render'], 'a7_cloudflare_settings', 'a7_cf_section');
	}

	public function api_token_render() {
		echo '<style>
		.a7-cf-main-wrap { max-width:100vw; width:100%; padding:0 2vw; box-sizing:border-box; }
		.a7-cf-flex-wrap { display: flex; gap: 32px; align-items: flex-start; flex-wrap: wrap; width:100%; }
		@media (max-width: 900px) { .a7-cf-flex-wrap { flex-direction: column; gap: 18px; } }
		.a7-cf-tutorial-box { flex: 1 1 340px; background:#fff; border-radius:10px; padding:36px 40px 28px 40px; box-shadow:none; font-size:1.08em; line-height:1.7; min-width:280px; max-width:600px; }
		@media (max-width:600px) { .a7-cf-tutorial-box, .a7-cf-form-box { padding:18px 8px; } }
		.a7-cf-form-box { flex: 1 1 340px; background:#fff; border-radius:10px; padding:32px 36px 28px 36px; box-shadow:0 2px 12px rgba(124,58,237,0.04); min-width:280px; max-width:440px; }
		.a7-cf-form-title { font-size:1.18em; color:#222; font-weight:600; margin-bottom:22px; }
		.a7-cf-form-group { display:flex; align-items:flex-start; gap:18px; margin-bottom:22px; }
		@media (max-width:600px) { .a7-cf-form-group { flex-direction:column; gap:6px; } }
		.a7-cf-form-label { min-width:90px; max-width:120px; font-weight:600; color:#7c3aed; margin-top:10px; white-space:nowrap; }
		.a7-cf-form-field-wrap { flex:1; }
		.a7-cf-form-box input[type=text] { width:100%; padding:12px 14px; border:1.5px solid #e5e7eb; border-radius:7px; font-size:1.08em; margin-bottom:6px; background:#f8fafd; transition: border-color .2s; }
		.a7-cf-form-box input[type=text]:focus { border-color:#7c3aed; outline:none; background:#f3e8ff; }
		.a7-cf-form-box .description { color:#666; font-size:0.98em; margin-bottom:0; margin-top:0; }
		.a7-cf-save-btn { background: linear-gradient(90deg,#7c3aed 60%,#2563eb 100%); color:#fff; border:none; border-radius:7px; padding:13px 36px; font-size:1.13em; font-weight:600; box-shadow:0 2px 12px rgba(124,58,237,0.08); cursor:pointer; margin-top:10px; transition: background .2s, box-shadow .2s, transform .1s; }
		.a7-cf-save-btn:hover { background: linear-gradient(90deg,#2563eb 60%,#7c3aed 100%); box-shadow:0 4px 24px rgba(124,58,237,0.13); transform: translateY(-2px) scale(1.03); }
		</style>';
		echo '<div class="a7-cf-main-wrap">';
		echo '<div class="a7-cf-flex-wrap">';
		echo '<div class="a7-cf-form-box">';
		echo '<form action="options.php" method="post" autocomplete="off">';
		settings_fields('a7_cf_group');
		echo '<div class="a7-cf-form-title">Configurações da API Cloudflare</div>';
		$options = get_option($this->option_name);
		// API Token
		echo '<div class="a7-cf-form-group">'
			. '<label class="a7-cf-form-label" for="a7-cf-api-token">API Token</label>'
			. '<div class="a7-cf-form-field-wrap">'
			. '<input id="a7-cf-api-token" type="text" name="' . $this->option_name . '[api_token]" value="' . esc_attr($options['api_token'] ?? '') . '" autocomplete="off">'
			. '<div class="description">Insira seu API Token do Cloudflare com permissões de leitura.</div>'
			. '</div>'
			. '</div>';
		// Zone ID
		echo '<div class="a7-cf-form-group">'
			. '<label class="a7-cf-form-label" for="a7-cf-zone-id">Zone ID</label>'
			. '<div class="a7-cf-form-field-wrap">'
			. '<input id="a7-cf-zone-id" type="text" name="' . $this->option_name . '[zone_id]" value="' . esc_attr($options['zone_id'] ?? '') . '" autocomplete="off">'
			. '<div class="description">Insira o Zone ID do seu site no Cloudflare.</div>'
			. '</div>'
			. '</div>';
		echo '<button type="submit" class="a7-cf-save-btn">Salvar Configurações</button>';
		echo '</form>';
		echo '</div>';
		echo '</div>';
		echo '</div></div>';
	}

	public function zone_id_render() {
		$options = get_option($this->option_name);
		echo "<input type='text' name='{$this->option_name}[zone_id]' value='" . esc_attr($options['zone_id'] ?? '') . "' style='width:400px;'>";
		echo '<p class="description">Insira o Zone ID do seu site no Cloudflare.</p>';
	}

	public function settings_page() {
		echo '<div class="wrap">';
		// Mensagem de sucesso ao salvar
		if (isset($_GET['settings-updated']) && $_GET['settings-updated']) {
			echo '<div style="margin-bottom:24px;margin-top:10px;padding:14px 22px;background:#22c55e;color:#fff;border-radius:7px;font-size:1.13em;font-weight:600;max-width:480px;box-shadow:0 2px 8px rgba(34,197,94,0.10);">Configurações salvas com sucesso!</div>';
		}
		echo '<h1 style="margin-top:30px;">A7 CloudFlare - Configurações</h1>';
		echo '<style>
		.a7-cf-main-wrap { max-width:100vw; width:100%; padding:0 2vw; box-sizing:border-box; }
		.a7-cf-flex-wrap { display: flex; gap: 32px; align-items: flex-start; flex-wrap: wrap; width:100%; }
		@media (max-width: 900px) { .a7-cf-flex-wrap { flex-direction: column; gap: 18px; } }
		.a7-cf-tutorial-box { flex: 1 1 340px; background:#fff; border-radius:10px; padding:36px 40px 28px 40px; box-shadow:none; font-size:1.08em; line-height:1.7; min-width:280px; max-width:600px; }
		@media (max-width:600px) { .a7-cf-tutorial-box, .a7-cf-form-box { padding:18px 8px; } }
		.a7-cf-form-box { flex: 1 1 340px; background:#fff; border-radius:10px; padding:32px 36px 28px 36px; box-shadow:0 2px 12px rgba(124,58,237,0.04); min-width:280px; max-width:440px; }
		.a7-cf-form-title { font-size:1.18em; color:#222; font-weight:600; margin-bottom:22px; }
		.a7-cf-form-group { display:flex; align-items:flex-start; gap:18px; margin-bottom:22px; }
		@media (max-width:600px) { .a7-cf-form-group { flex-direction:column; gap:6px; } }
		.a7-cf-form-label { min-width:90px; max-width:120px; font-weight:600; color:#7c3aed; margin-top:10px; white-space:nowrap; }
		.a7-cf-form-field-wrap { flex:1; }
		.a7-cf-form-box input[type=text] { width:100%; padding:12px 14px; border:1.5px solid #e5e7eb; border-radius:7px; font-size:1.08em; margin-bottom:6px; background:#f8fafd; transition: border-color .2s; }
		.a7-cf-form-box input[type=text]:focus { border-color:#7c3aed; outline:none; background:#f3e8ff; }
		.a7-cf-form-box .description { color:#666; font-size:0.98em; margin-bottom:0; margin-top:0; }
		.a7-cf-save-btn { background: linear-gradient(90deg,#7c3aed 60%,#2563eb 100%); color:#fff; border:none; border-radius:7px; padding:13px 36px; font-size:1.13em; font-weight:600; box-shadow:0 2px 12px rgba(124,58,237,0.08); cursor:pointer; margin-top:10px; transition: background .2s, box-shadow .2s, transform .1s; }
		.a7-cf-save-btn:hover { background: linear-gradient(90deg,#2563eb 60%,#7c3aed 100%); box-shadow:0 4px 24px rgba(124,58,237,0.13); transform: translateY(-2px) scale(1.03); }
		</style>';
		echo '<div class="a7-cf-main-wrap">';
		echo '<div class="a7-cf-flex-wrap">';
		// Tutorial visual
		echo '<div class="a7-cf-tutorial-box">'
			. '<h2 style="color:#7c3aed;font-size:1.3em;margin-top:0;margin-bottom:12px;">✅ Como obter o API Token e o Zone ID no Cloudflare</h2>'
			. '<ol style="margin-left:18px;padding-left:0;">
<li><b>🛠️ 1. Acessar o painel do Cloudflare</b><br>
Vá para: <a href="https://dash.cloudflare.com" target="_blank">https://dash.cloudflare.com</a><br>
Faça login com sua conta.</li>
<li><b>🔑 2. Criar um API Token com permissões de leitura</b><br>
No menu superior direito, clique no seu ícone de perfil &gt; <b>My Profile</b>.<br>
Vá até a aba <b>API Tokens</b>.<br>
Clique em <b>Create Token</b>.<br>
Use o template <b>Read all zones</b> ou clique em <b>Custom Token</b> e configure assim:<br>
<ul style="margin:8px 0 8px 18px;">
<li>Zone.Zone: <b>Read</b></li>
<li>Zone.DNS: <b>Read</b> <span style="color:#888;font-size:0.97em;">(se for necessário acessar registros DNS)</span></li>
<li>Zone.Cache Purge: <b>Read</b> <span style="color:#888;font-size:0.97em;">(consultar estatísticas de cache, opcional)</span></li>
</ul>
Defina o zone resource como: <b>Include → Specific zone → selecione seu domínio</b>.<br>
Clique em <b>Create Token</b>.<br>
<span style="color:#7c3aed;font-weight:600;">Copie o token gerado</span> (você não poderá ver ele novamente depois).<br>
<span style="color:#e11d48;font-weight:600;">💡 Importante:</span> Nunca compartilhe publicamente esse token. Ele é sensível e permite acesso à sua conta!</li>
<li><b>🌐 3. Obter o Zone ID</b><br>
No painel do Cloudflare, clique no domínio desejado.<br>
Na barra lateral esquerda, role até o final.<br>
Você verá o <b>Zone ID</b> no bloco "API" ou "Zone ID" (fica no final da página).</li>
</ol>'
			. '<div style="background:#fffbe7;border-radius:7px;padding:12px 18px;margin:18px 0 0 0;border-left:4px solid #7c3aed;">
<b>✅ Exemplo de uso:</b><br>
API Token: usado para autenticação em chamadas à API.<br>
Zone ID: identifica o site que será consultado ou manipulado.
</div>'
			. '</div>';
		// Formulário moderno
		echo '<div class="a7-cf-form-box">';
		echo '<form action="options.php" method="post" autocomplete="off">';
		settings_fields('a7_cf_group');
		echo '<div class="a7-cf-form-title">Configurações da API Cloudflare</div>';
		$options = get_option($this->option_name);
		// API Token
		echo '<div class="a7-cf-form-group">'
			. '<label class="a7-cf-form-label" for="a7-cf-api-token">API Token</label>'
			. '<div class="a7-cf-form-field-wrap">'
			. '<input id="a7-cf-api-token" type="text" name="' . $this->option_name . '[api_token]" value="' . esc_attr($options['api_token'] ?? '') . '" autocomplete="off">'
			. '<div class="description">Insira seu API Token do Cloudflare com permissões de leitura.</div>'
			. '</div>'
			. '</div>';
		// Zone ID
		echo '<div class="a7-cf-form-group">'
			. '<label class="a7-cf-form-label" for="a7-cf-zone-id">Zone ID</label>'
			. '<div class="a7-cf-form-field-wrap">'
			. '<input id="a7-cf-zone-id" type="text" name="' . $this->option_name . '[zone_id]" value="' . esc_attr($options['zone_id'] ?? '') . '" autocomplete="off">'
			. '<div class="description">Insira o Zone ID do seu site no Cloudflare.</div>'
			. '</div>'
			. '</div>';
		echo '<button type="submit" class="a7-cf-save-btn">Salvar Configurações</button>';
		echo '</form>';
		echo '</div>';
		echo '</div>';
		echo '</div></div>';
	}

	public function metrics_page() {
		// PopUp de informações do plugin
		echo <<<HTML
		<!-- Botão para abrir o PopUp -->
		<div style="position:fixed;top:calc(32px + 25px);right:30px;z-index:10001;">
			<button id="a7-cf-info-btn" style="background:#2563eb;color:#fff;padding:10px 22px;border:none;border-radius:7px;cursor:pointer;font-size:16px;box-shadow:0 2px 8px rgba(34,99,235,0.10);font-family:inherit;">
				Sobre o Plugin
			</button>
		</div>
		<!-- Modal PopUp -->
		<div id="a7-cf-modal" style="display:none;position:fixed;z-index:9999;left:0;top:0;width:100vw;height:100vh;background:rgba(0,0,0,0.35);align-items:center;justify-content:center;">
			<div style="background:#fff;max-width:520px;width:92vw;margin:auto;padding:32px 24px 24px 24px;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.18);position:relative;font-family:inherit;max-height:600px;overflow-y:auto;">
				<button id="a7-cf-modal-close" style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:28px;cursor:pointer;line-height:1;">&times;</button>
				<h2 style="margin-top:0;font-size:1.7em;color:#7c3aed;font-weight:700;">A7 CloudFlare</h2>
				<p style="font-size:1.1em;color:#333;">Plugin WordPress para exibir métricas básicas do Cloudflare em uma página administrativa com gráficos elegantes e configurações simples. Ideal para acompanhar o desempenho e segurança do seu site diretamente no painel do WordPress.</p>
				<hr style="margin:18px 0;">
				<h3 style="font-size:1.1em;color:#7c3aed;margin-bottom:8px;">Recursos</h3>
				<ul style="margin:0 0 16px 18px;padding:0;font-size:1em;color:#222;">
					<li>Configuração de API Token e Zone ID do Cloudflare.</li>
					<li>Exibição de gráficos com dados de Requests, Cached Requests, Threats, Bytes transferidos e Encrypted Requests dos últimos 7 dias.</li>
					<li>Interface em português com datas no formato brasileiro (dd/mm/aaaa).</li>
					<li>Layout responsivo e colorido para facilitar a leitura.</li>
				</ul>
				<h3 style="font-size:1.1em;color:#7c3aed;margin-bottom:8px;">Instalação</h3>
				<ol style="margin:0 0 16px 18px;padding:0;font-size:1em;color:#222;">
					<li>Faça o download ou copie o arquivo <code>a7-cloudflare.php</code> para a pasta <code>/wp-content/plugins/a7-cloudflare/</code>.</li>
					<li>Acesse o painel administrativo do WordPress.</li>
					<li>Vá até <b>Plugins &gt; Plugins Instalados</b> e ative o plugin <b>A7 CloudFlare</b>.</li>
					<li>No menu lateral do WordPress, acesse <b>Configurações &gt; A7 CloudFlare</b>.</li>
					<li>Informe seu <b>API Token</b> do Cloudflare (com permissões de leitura) e o <b>Zone ID</b> do seu site.</li>
					<li>Salve as configurações.</li>
					<li>O plugin irá exibir os gráficos na página principal do plugin.</li>
				</ol>
				<h3 style="font-size:1.1em;color:#7c3aed;margin-bottom:8px;">Requisitos</h3>
				<ul style="margin:0 0 16px 18px;padding:0;font-size:1em;color:#222;">
					<li>WordPress 5.0 ou superior.</li>
					<li>PHP 7.4 ou superior.</li>
					<li>Permissões adequadas para acessar a API do Cloudflare (API Token com escopo de leitura).</li>
					<li>Acesso à internet para carregar a biblioteca Chart.js via CDN.</li>
				</ul>
				<h3 style="font-size:1.1em;color:#7c3aed;margin-bottom:8px;">Suporte e Contato</h3>
				<ul style="margin:0 0 16px 18px;padding:0;font-size:1em;color:#222;">
					<li>WhatsApp: <a href="https://wa.me/5531995999029" target="_blank">+55 31 9 9599 9029</a></li>
					<li>Email: <a href="mailto:comercial@a7site.com.br">comercial@a7site.com.br</a></li>
					<li>Site: <a href="https://a7site.com.br" target="_blank">https://a7site.com.br</a></li>
				</ul>
				<h3 style="font-size:1.1em;color:#7c3aed;margin-bottom:8px;">Autor</h3>
				<p style="margin:0 0 12px 0;font-size:1em;color:#222;">Alexandro F. S. Martins - Programador Web<br><a href="https://a7site.com.br" target="_blank">https://a7site.com.br</a></p>
				<h3 style="font-size:1.1em;color:#7c3aed;margin-bottom:8px;">Licença</h3>
				<p style="margin:0 0 0 0;font-size:1em;color:#222;">GPL v2 ou superior</p>
				<div style="margin-top:18px;text-align:center;font-size:1.1em;color:#7c3aed;font-weight:600;">Obrigado por usar o A7 CloudFlare!</div>
			</div>
		</div>
		<script>
		document.addEventListener('DOMContentLoaded', function() {
			var btn = document.getElementById('a7-cf-info-btn');
			var modal = document.getElementById('a7-cf-modal');
			var close = document.getElementById('a7-cf-modal-close');
			if(btn && modal && close) {
				btn.onclick = function() { modal.style.display = 'flex'; }
				close.onclick = function() { modal.style.display = 'none'; }
				window.onclick = function(e) { if(e.target === modal) modal.style.display = 'none'; }
			}
		});
		</script>
		HTML;
		// Exibir logomarca e dados de desenvolvimento acima do título
		echo '<div style="text-align:center;margin-top:24px;margin-bottom:8px;">'
			. '<img src="https://a7site.com.br/wp-content/uploads/2025/04/a7site.svg" alt="Logo A7 Sites" style="max-width:420px;height:auto;display:block;margin:0 auto 10px auto;">'
			. '<p style="margin:0;font-size:15px;color:#555;font-weight:400;">Desenvolvido pela <strong>A7 Sites Developers</strong> | por Alexandro F. S. Martins - APIRest FULL</p>'
			. '</div>';
		echo '<div class="wrap" style="padding:0 20px;">';
		echo '<h1 style="text-align:center;margin-bottom:38px;">A7 CloudFlare - Métricas</h1>';

		$metrics7d = $this->fetch_cloudflare_data(7);
		$metrics24h = $this->fetch_cloudflare_data(1, true);

		if (isset($metrics7d['error'])) {
			echo '<p style="color:red;text-align:center;"><strong>' . esc_html($metrics7d['error']) . '</strong></p>';
		} else {
			echo '<h2 style="text-align:center;">Informações do Servidor CloudFlare nos últimos 7 dias</h2><canvas id="a7_cf_chart_7d" style="width:100%;height:400px;"></canvas>';
		}

		if (isset($metrics24h['error'])) {
			echo '<p style="color:red;text-align:center;"><strong>' . esc_html($metrics24h['error']) . '</strong></p>';
		} else {
			echo '<h2 style="text-align:center; margin-top: 50px;">Dados de acesso das últimas 24 horas Servidor (CloudFlare - A7 Sites)</h2><canvas id="a7_cf_chart_24h" style="width:100%;height:400px;"></canvas>';
		}

		// Bloco moderno de 30 dias
		$metrics30d = $this->fetch_cloudflare_data(30);
		// Array de dias da semana em português (abreviado)
		$dias_pt = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
		// Inicializar variáveis para evitar warnings
		$labels30d = [];
		$cache_bytes30d = [];
		$nocache_bytes30d = [];
		$total_bytes30d = [];
		$sum_bytes_30d = 0;
		$sum_cache_30d = 0;
		$sum_nocache_30d = 0;
		// Organizar datas em ordem crescente para o gráfico de 30 dias
		if (is_array($metrics30d)) {
			usort($metrics30d, function ($a, $b) {
				$dateA = isset($a['dimensions']['date']) ? strtotime($a['dimensions']['date']) : 0;
				$dateB = isset($b['dimensions']['date']) ? strtotime($b['dimensions']['date']) : 0;
				return $dateA <=> $dateB;
			});
			foreach ($metrics30d as $d) {
				$date = isset($d['dimensions']['date']) ? date('d/m', strtotime($d['dimensions']['date'])) : '';
				$weekday_num = isset($d['dimensions']['date']) ? date('w', strtotime($d['dimensions']['date'])) : 0;
				$weekday = $dias_pt[$weekday_num];
				$labels30d[] = $date . "\n" . $weekday;
				$cache = (float)($d['sum']['cachedRequests'] ?? 0);
				$total = (float)($d['sum']['requests'] ?? 0);
				$bytes = (float)($d['sum']['bytes'] ?? 0);
				$cache_bytes_val = $total > 0 ? ($bytes * ($cache/$total)) : 0;
				$nocache_bytes_val = $bytes - $cache_bytes_val;
				$cache_bytes30d[] = round($cache_bytes_val, 2);
				$nocache_bytes30d[] = round($nocache_bytes_val, 2);
				$total_bytes30d[] = round($bytes, 2);
				$sum_bytes_30d += $bytes;
				$sum_cache_30d += $cache_bytes_val;
				$sum_nocache_30d += $nocache_bytes_val;
			}
		}
		echo '<div style="margin-top:48px;">';
		echo '<style>
		.a7-cf-cards-row {display:flex;gap:32px;margin-bottom:24px;}
		.a7-cf-card {
			flex:1;
			padding:18px 12px;
			border-radius:8px;
			text-align:center;
			color:#fff;
			position:relative;
			cursor:pointer;
			transition: box-shadow 0.2s, transform 0.2s;
			box-shadow: 0 2px 8px rgba(0,0,0,0.10);
		}
		.a7-cf-card:hover {
			box-shadow: 0 8px 32px 0 rgba(34,113,177,0.18), 0 2px 8px 0 rgba(34,113,177,0.10);
			transform: translateY(-4px) scale(1.03);
		}
		.a7-cf-tooltip {
			display:none;
			position:absolute;
			left:50%;
			top:0;
			transform:translate(-50%,-110%);
			background:#18181b;
			color:#fff;
			padding:7px 16px;
			border-radius:6px;
			font-size:13px;
			white-space:nowrap;
			z-index:10;
			box-shadow:0 2px 8px rgba(0,0,0,0.18);
			pointer-events:none;
		}
		.a7-cf-card:hover .a7-cf-tooltip {
			display:block;
			animation: fade-in .2s;
		}
		</style>';
		echo '<div class="a7-cf-cards-row">';
		echo '<div class="a7-cf-card" style="background:#2563eb;">
				<div class="a7-cf-tooltip">Data de hoje: ' . date('d/m/Y') . '</div>
				<div style="font-size:15px;opacity:.85;">Largura de banda total<br><span style="font-size:12px;">30 dias anteriores</span></div>
				<div style="font-size:2em;font-weight:bold;">' . format_bytes_30d($sum_bytes_30d) . '</div>
			  </div>';
		echo '<div class="a7-cf-card" style="background:#7c3aed;">
				<div class="a7-cf-tooltip">Data de hoje: ' . date('d/m/Y') . '</div>
				<div style="font-size:15px;opacity:.85;">Largura de banda em cache<br><span style="font-size:12px;">30 dias anteriores</span></div>
				<div style="font-size:2em;font-weight:bold;">' . format_bytes_30d($sum_cache_30d) . '</div>
			  </div>';
		echo '<div class="a7-cf-card" style="background:#fb923c;">
				<div class="a7-cf-tooltip">Data de hoje: ' . date('d/m/Y') . '</div>
				<div style="font-size:15px;opacity:.85;">Largura de banda sem cache<br><span style="font-size:12px;">30 dias anteriores</span></div>
				<div style="font-size:2em;font-weight:bold;">' . format_bytes_30d($sum_nocache_30d) . '</div>
			  </div>';
		echo '</div>';
		echo '<canvas id="a7_cf_chart_30d" style="width:100%;height:400px;"></canvas>';
		echo '</div>';
		// Após o gráfico de 30 dias, calcular e exibir os 3 cards pequenos à direita
		$sum_bytes_saved = 0;
		$sum_ssl_requests = 0;
		$sum_threats = 0;
		if (is_array($metrics30d)) {
			foreach ($metrics30d as $d) {
				$sum_bytes_saved += (float)($d['sum']['bytes'] ?? 0);
				$sum_ssl_requests += (float)($d['sum']['encryptedRequests'] ?? 0);
				$sum_threats += (float)($d['sum']['threats'] ?? 0);
			}
		}
		echo '<div style="width:100%;display:flex;flex-direction:column;align-items:center;margin:38px 0 0 0;">';
		echo '<div style="width:100%;text-align:center;font-size:1.18em;font-weight:600;margin-bottom:18px;color:#18181b;">Suas Estatísticas no Sistema A7 Cloud</div>';
		echo '<div style="display:flex;justify-content:center;gap:24px;width:100%;max-width:700px;">';
		echo '<div style="background:#2563eb;color:#fff;padding:16px 22px 14px 22px;border-radius:10px;min-width:170px;text-align:center;box-shadow:0 2px 8px rgba(37,99,235,0.10);font-size:15px;line-height:1.4;display:flex;flex-direction:column;align-items:center;">
				<span style="font-size:13px;opacity:.85;">Bytes salvos:</span>
				<span style="font-size:1.35em;font-weight:600;margin-top:2px;">' . format_bytes_30d($sum_bytes_saved) . '</span>
			  </div>';
		echo '<div style="background:#22c55e;color:#fff;padding:16px 22px 14px 22px;border-radius:10px;min-width:170px;text-align:center;box-shadow:0 2px 8px rgba(34,197,94,0.10);font-size:15px;line-height:1.4;display:flex;flex-direction:column;align-items:center;">
				<span style="font-size:13px;opacity:.85;">Solicitações SSL atendidas:</span>
				<span style="font-size:1.35em;font-weight:600;margin-top:2px;">' . number_format($sum_ssl_requests, 0, ',', '.') . '</span>
			  </div>';
		echo '<div style="background:#b91c1c;color:#fff;padding:16px 22px 14px 22px;border-radius:10px;min-width:170px;text-align:center;box-shadow:0 2px 8px rgba(185,28,28,0.10);font-size:15px;line-height:1.4;display:flex;flex-direction:column;align-items:center;">
				<span style="font-size:13px;opacity:.85;">Ataques bloqueados:</span>
				<span style="font-size:1.35em;font-weight:600;margin-top:2px;">' . number_format($sum_threats, 0, ',', '.') . '</span>
			  </div>';
		echo '</div>';
		echo '<div style="width:100%;max-width:700px;display:flex;justify-content:center;margin-top:6px;">';
		echo '<span style="font-size:12px;color:#666;text-align:center;">* Os dados são de sua conta na A7 Sites configurado diretamente pelo Sistema de CDN CloudFlare da Empresa.</span>';
		echo '</div>';
		echo '</div>';
		?>
        <script>
		document.addEventListener('DOMContentLoaded', function() {
			// Gráfico 7 dias
	            <?php if (!isset($metrics7d['error'])) :
	            usort($metrics7d, function ($a, $b) {
		            $dateA = isset($a['dimensions']['date']) ? strtotime($a['dimensions']['date']) : 0;
		            $dateB = isset($b['dimensions']['date']) ? strtotime($b['dimensions']['date']) : 0;
		            return $dateA <=> $dateB;
	            });
	            $labels7d = array_map(fn($d) => date('d/m/Y', strtotime($d['dimensions']['date'])), $metrics7d);
	            $requests7d = array_map(fn($d) => (int)$d['sum']['requests'], $metrics7d);
	            $cache7d = array_map(fn($d) => (int)$d['sum']['cachedRequests'], $metrics7d);
	            $threats7d = array_map(fn($d) => (int)$d['sum']['threats'], $metrics7d);
	            $bytes7d = array_map(fn($d) => round($d['sum']['bytes'] / (1024*1024), 2), $metrics7d);
	            $encrypted7d = array_map(fn($d) => (int)$d['sum']['encryptedRequests'], $metrics7d);
	            ?>
                new Chart(document.getElementById('a7_cf_chart_7d'), {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode($labels7d); ?>,
                        datasets: [
                            {
                                label: 'Requisições',
                                data: <?php echo json_encode($requests7d); ?>,
                                borderColor: 'rgba(54, 162, 235, 1)',
                                backgroundColor: 'rgba(54, 162, 235, 0.2)',
							borderWidth: 2,
                                fill: true,
                                tension: 0.4,
							pointRadius: 4,
                                pointHoverRadius: 4,
                                pointBackgroundColor: 'rgba(54, 162, 235, 1)'
                            },
                            {
                                label: 'Requisições em Cache',
                                data: <?php echo json_encode($cache7d); ?>,
                                backgroundColor: 'rgba(75,206,40, 0.1)',
                                borderColor: 'rgba(75,206,40, 1)',
							borderWidth: 2,
                                fill: true,
                                tension: 0.4,
							pointRadius: 4,
                                pointHoverRadius: 4,
                                pointBackgroundColor: 'rgba(75,206,40, 1)'
                            },
                            {
                                label: 'Ameaças',
                                data: <?php echo json_encode($threats7d); ?>,
                                borderColor: 'rgba(255, 99, 132, 1)',
                                backgroundColor: 'rgba(255, 99, 132, 0.2)',
							borderWidth: 2,
                                fill: true,
                                tension: 0.4,
							pointRadius: 4,
                                pointHoverRadius: 4,
                                pointBackgroundColor: 'rgba(255, 99, 132, 1)'
                            },
                            {
                                label: 'Bytes (MB)',
                                data: <?php echo json_encode($bytes7d); ?>,
                                borderColor: 'rgba(153, 102, 255, 1)',
                                backgroundColor: 'rgba(153, 102, 255, 0.2)',
							borderWidth: 2,
                                fill: true,
                                tension: 0.4,
                                yAxisID: 'y1',
							pointRadius: 4,
                                pointHoverRadius: 4,
                                pointBackgroundColor: 'rgba(153, 102, 255, 1)'
                            },
                            {
                                label: 'Requisições Criptografadas',
                                data: <?php echo json_encode($encrypted7d); ?>,
                                borderColor: 'rgba(255, 206, 86, 1)',
                                backgroundColor: 'rgba(255, 206, 86, 0.1)',
							borderWidth: 2,
                                fill: true,
                                tension: 0.4,
							pointRadius: 4,
                                pointHoverRadius: 4,
                                pointBackgroundColor: 'rgba(255, 206, 86, 1)'
                            }
                        ]
                    },
                    options: {
                        scales: {
                            y: {
                                beginAtZero: true,
                                position: 'left',
                                title: { display: true, text: 'Contagem' }
                            },
                            y1: {
                                beginAtZero: true,
                                position: 'right',
                                grid: { drawOnChartArea: false },
                                title: { display: true, text: 'Bytes (MB)' }
                            }
                        },
                        interaction: {
                            mode: 'index',
                            intersect: false
                        },
                        plugins: {
                            tooltip: { enabled: true }
                        }
                    }
                });
				<?php endif; ?>
			// Gráfico 24 horas
			<?php if (!isset($metrics24h['error'])) :
	            // Garantir sequência 00h-23h, mesmo se faltar dado
	            $labels24h = [];
	            $requests24h = [];
	            $cache24h = [];
	            $threats24h = [];
	            $bytes24h = [];
	            // Mapear dados por hora
	            $data_by_hour = [];
	            $base_date = null;
	            foreach ($metrics24h as $d) {
	                $dt = isset($d['dimensions']['datetime']) ? $d['dimensions']['datetime'] : '';
	                if ($dt) {
	                    $h = (int)date('H', strtotime($dt));
	                    $data_by_hour[$h] = [
	                        'requests' => (int)($d['sum']['requests'] ?? 0),
	                        'cachedRequests' => (int)($d['sum']['cachedRequests'] ?? 0),
	                        'threats' => (int)($d['sum']['threats'] ?? 0),
	                        'bytes' => isset($d['sum']['bytes']) ? round($d['sum']['bytes'] / (1024 * 1024), 2) : 0,
	                        'dt' => $dt
	                    ];
	                    if ($base_date === null) $base_date = date('Y-m-d', strtotime($dt));
	                }
	            }
	            // Preencher as 24 horas
	            for ($h = 0; $h < 24; $h++) {
	                $label_dt = $base_date ? $base_date . ' ' . sprintf('%02d:00:00', $h) : '';
	                $labels24h[] = $label_dt ? date('d/m H\h', strtotime($label_dt)) : sprintf('%02d:00', $h);
	                $requests24h[] = isset($data_by_hour[$h]) ? $data_by_hour[$h]['requests'] : 0;
	                $cache24h[] = isset($data_by_hour[$h]) ? $data_by_hour[$h]['cachedRequests'] : 0;
	                $threats24h[] = isset($data_by_hour[$h]) ? $data_by_hour[$h]['threats'] : 0;
	                $bytes24h[] = isset($data_by_hour[$h]) ? $data_by_hour[$h]['bytes'] : 0;
	            }
	            ?>
                new Chart(document.getElementById('a7_cf_chart_24h'), {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($labels24h); ?>,
                        datasets: [
                            {
                                label: 'Requisições',
                                data: <?php echo json_encode($requests24h); ?>,
                                borderColor: 'rgba(54, 162, 235, 1)',
                                backgroundColor: 'rgba(54, 162, 235, 0.0)',
                                borderWidth: 2,
                                yAxisID: 'y',
                                type: 'line',
                                fill: false,
                                tension: 0.3,
                                pointRadius: 3,
                                pointHoverRadius: 4,
                                pointBackgroundColor: 'rgba(54, 162, 235, 1)'
                            },
                            {
                                label: 'Requisições em Cache',
                                data: <?php echo json_encode($cache24h); ?>,
                                backgroundColor: 'rgba(75,206,40, 0.7)',
                                borderColor: 'rgba(75,206,40, 1)',
                                borderWidth: 2,
                                yAxisID: 'y',
                                type: 'bar',
                            },
                            {
                                label: 'Ameaças',
                                data: <?php echo json_encode($threats24h); ?>,
                                backgroundColor: 'rgba(255, 0, 0, 0.15)',
                                borderColor: 'rgba(255, 0, 0, 1)',
                                borderWidth: 2,
                                yAxisID: 'y',
                                type: 'bar',
                            },
                            {
                                label: 'Bytes (MB)',
                                data: <?php echo json_encode($bytes24h); ?>,
                                type: 'line',
                                borderColor: 'rgba(153, 102, 255, 1)',
                                backgroundColor: 'rgba(153, 102, 255, 0.0)',
                                borderWidth: 2,
                                fill: false,
                                tension: 0.4,
                                yAxisID: 'y1',
                                pointRadius: 3,
                                pointHoverRadius: 4,
                                pointBackgroundColor: 'rgba(153, 102, 255, 1)'
                            }
                        ]
                    },
                    options: {
                        scales: {
                            y: {
                                beginAtZero: true,
                                position: 'left',
                                title: { display: true, text: 'Contagem' }
                            },
                            y1: {
                                beginAtZero: true,
                                position: 'right',
                                grid: { drawOnChartArea: false },
                                title: { display: true, text: 'Bytes (MB)' }
                            },
                            x: {
                                title: { display: true, text: 'Data e Hora' },
                                ticks: { color: '#18181b' }
                            }
                        },
                        plugins: {
                            tooltip: { enabled: true }
                        },
                        plugins: [
                            {
                                afterDraw: function(chart) {
                                    // DEBUG: Listar labels dos datasets
                                    console.log('Datasets:', chart.data.datasets.map(ds => ds.label));
                                    // Tentar encontrar o dataset de ameaças
                                    const dataset = chart.data.datasets.find(ds => ds.label === 'Ameaças');
                                    if (!dataset) {
                                        console.warn('Dataset de ameaças não encontrado! Labels disponíveis:', chart.data.datasets.map(ds => ds.label));
                                        return;
                                    }
                                    const meta = chart.getDatasetMeta(chart.data.datasets.indexOf(dataset));
                                    // Tooltip custom
                                    if (!window.a7cfThreatTooltip) {
                                        window.a7cfThreatTooltip = document.createElement('div');
                                        window.a7cfThreatTooltip.style.position = 'fixed';
                                        window.a7cfThreatTooltip.style.background = '#dc2626';
                                        window.a7cfThreatTooltip.style.color = '#fff';
                                        window.a7cfThreatTooltip.style.padding = '10px 22px';
                                        window.a7cfThreatTooltip.style.borderRadius = '10px';
                                        window.a7cfThreatTooltip.style.fontSize = '17px';
                                        window.a7cfThreatTooltip.style.fontWeight = 'bold';
                                        window.a7cfThreatTooltip.style.pointerEvents = 'none';
                                        window.a7cfThreatTooltip.style.zIndex = 99999;
                                        window.a7cfThreatTooltip.style.boxShadow = '0 4px 24px rgba(220,38,38,0.25)';
                                        window.a7cfThreatTooltip.style.display = 'none';
                                        window.a7cfThreatTooltip.style.transition = 'opacity 0.2s';
                                        document.body.appendChild(window.a7cfThreatTooltip);
                                    }
                                    // Detectar mouse
                                    chart.canvas.addEventListener('mousemove', function(e) {
                                        const rect = chart.canvas.getBoundingClientRect();
                                        const mouseX = e.clientX - rect.left;
                                        const mouseY = e.clientY - rect.top;
                                        let found = false;
                                        dataset.data.forEach((value, i) => {
                                            if (value > 0) {
                                                const bar = meta.data[i];
                                                if (bar) {
                                                    const x = bar.x;
                                                    const y = bar.y - 28;
                                                    const dist = Math.sqrt(Math.pow(mouseX - x, 2) + Math.pow(mouseY - y, 2));
                                                    if (dist < 22) {
                                                        window.a7cfThreatTooltip.innerHTML = '🚨 <b>' + value + '</b> ameaça'+(value>1?'s':'')+' detectada'+(value>1?'s':'')+'!';
                                                        window.a7cfThreatTooltip.style.left = (e.clientX + 16) + 'px';
                                                        window.a7cfThreatTooltip.style.top = (e.clientY - 38) + 'px';
                                                        window.a7cfThreatTooltip.style.display = 'block';
                                                        window.a7cfThreatTooltip.style.opacity = 1;
                                                        found = true;
                                                    }
                                                }
                                            }
                                        });
                                        if (!found) {
                                            window.a7cfThreatTooltip.style.opacity = 0;
                                            setTimeout(()=>{window.a7cfThreatTooltip.style.display='none';},200);
                                        }
                                    });
                                    chart.canvas.addEventListener('mouseleave', function() {
                                        window.a7cfThreatTooltip.style.opacity = 0;
                                        setTimeout(()=>{window.a7cfThreatTooltip.style.display='none';},200);
                                    });
                                    // Desenho visual
                                    dataset.data.forEach((value, i) => {
                                        if (value > 0) {
                                            const bar = meta.data[i];
                                            if (bar) {
                                                ctx.save();
                                                const x = bar.x;
                                                const y = bar.y - 28;
                                                ctx.beginPath();
                                                ctx.arc(x, y, 20, 0, 2 * Math.PI, false);
                                                ctx.fillStyle = '#dc2626';
                                                ctx.shadowColor = '#dc2626';
                                                ctx.shadowBlur = 18;
                                                ctx.fill();
                                                ctx.lineWidth = 5;
                                                ctx.strokeStyle = '#fff';
                                                ctx.stroke();
                                                ctx.shadowBlur = 0;
                                                ctx.font = 'bold 28px sans-serif';
                                                ctx.fillStyle = '#fff';
                                                ctx.textAlign = 'center';
                                                ctx.textBaseline = 'middle';
                                                ctx.fillText('!', x, y + 2);
                                                ctx.restore();
                                            }
                                        }
                                    });
                                }
                            }
                        ]
                    }
                });
	            <?php endif; ?>
			// Gráfico 30 dias
			new Chart(document.getElementById('a7_cf_chart_30d'), {
				type: 'line',
				data: {
					labels: <?php echo json_encode($labels30d); ?>,
					datasets: [
						{
							label: 'Em cache',
							data: <?php echo json_encode($cache_bytes30d); ?>,
							backgroundColor: 'rgba(54, 162, 235, 0.2)',
							borderColor: 'rgba(54, 162, 235, 1)',
							fill: true,
							stack: 'Stack 0',
							tension: 0.4,
							borderWidth: 2,
							pointRadius: 4,
							pointHoverRadius: 4,
							pointBackgroundColor: 'rgba(54, 162, 235, 1)'
						},
						{
							label: 'Sem cache',
							data: <?php echo json_encode($nocache_bytes30d); ?>,
							backgroundColor: 'rgba(251, 146, 60, 0.25)',
							borderColor: '#fb923c',
							fill: true,
							stack: 'Stack 0',
							tension: 0.4,
							borderWidth: 2,
							pointRadius: 4,
							pointHoverRadius: 4,
							pointBackgroundColor: 'rgba(251, 146, 60, 1)'
						},
						{
							label: 'Total',
							data: <?php echo json_encode($total_bytes30d); ?>,
							backgroundColor: 'rgba(124, 58, 237, 0.12)',
							borderColor: '#7c3aed',
							fill: false,
							borderWidth: 2,
							pointRadius: 4,
							pointHoverRadius: 4,
							pointBackgroundColor: 'rgba(124, 58, 237, 1)',
							tension: 0.4,
							type: 'line',
							order: 0
						}
					]
				},
				options: {
					responsive: true,
					plugins: {
						legend: { position: 'top' },
						tooltip: {
							mode: 'index',
							intersect: false,
							callbacks: {
								label: function(context) {
									let label = context.dataset.label || '';
									let value = context.parsed.y;
									let formatted = '';
									if (value >= 1073741824) {
										formatted = (value / 1073741824).toFixed(2) + ' GB';
									} else if (value >= 1048576) {
										formatted = (value / 1048576).toFixed(2) + ' MB';
									} else if (value >= 1024) {
										formatted = (value / 1024).toFixed(2) + ' KB';
									} else {
										formatted = value + ' B';
									}
									return label + ': ' + formatted;
								}
							}
						}
					},
					interaction: { mode: 'index', intersect: false },
					scales: {
						y: {
							stacked: false,
							title: { display: true, text: 'Largura de banda' },
							ticks: {
								callback: function(value) {
									if (value >= 1073741824) return (value/1073741824).toFixed(2)+' GB';
									if (value >= 1048576) return (value/1048576).toFixed(2)+' MB';
									if (value >= 1024) return (value/1024).toFixed(2)+' KB';
									return value + ' B';
								}
							}
						},
						x: {
							title: { display: true, text: 'Data' },
							ticks: {
								callback: function(val, idx, ticks) {
									let label = this.getLabelForValue(val);
									if (label.includes('\n')) {
										let [date, day] = label.split('\n');
										return date+'\n'+day;
									}
									return label;
								},
								font: { weight: 'normal' },
								color: '#18181b'
							}
						}
					}
				}
			});
            });
        </script>
		<?php
	}

	public function enqueue_scripts($hook) {
		if (!in_array($hook, ['toplevel_page_a7_cloudflare_metrics', 'a7_cloudflare_page_a7_cloudflare_settings'])) return;
		wp_enqueue_script('chartjs', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
		wp_enqueue_style('a7_cf_inter_font', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap');
		wp_enqueue_script('chartjs-rounded-bar', 'https://cdn.jsdelivr.net/npm/chartjs-plugin-rounded-bar@3.0.0', ['chartjs'], null, true);
		wp_enqueue_style('leaflet', 'https://unpkg.com/leaflet/dist/leaflet.css');
		wp_enqueue_script('leaflet', 'https://unpkg.com/leaflet/dist/leaflet.js', [], null, true);
	}

	private function fetch_cloudflare_data($days = 7, $hourly = false) {
		$options = get_option($this->option_name);
		if (empty($options['api_token']) || empty($options['zone_id'])) return ['error' => 'API Token ou Zone ID não configurados.'];

		$api_token = $options['api_token'];
		$zone_id = $options['zone_id'];

		if ($hourly) {
			$start = date('Y-m-d\TH:00:00\Z', strtotime('-24 hours'));
			$query = <<<GRAPHQL
{
  viewer {
    zones(filter: { zoneTag: "$zone_id" }) {
      httpRequests1hGroups(limit: 24, filter: { datetime_gt: "$start" }) {
        dimensions { datetime }
        sum { requests cachedRequests threats bytes }
      }
    }
  }
}
GRAPHQL;
		} else {
			$start = date('Y-m-d', strtotime('-' . $days . ' days'));
			$query = <<<GRAPHQL
{
  viewer {
    zones(filter: { zoneTag: "$zone_id" }) {
      httpRequests1dGroups(limit: $days, filter: { date_gt: "$start" }) {
        dimensions { date }
        sum { requests cachedRequests threats bytes encryptedRequests }
      }
    }
  }
}
GRAPHQL;
		}

		$ch = curl_init('https://api.cloudflare.com/client/v4/graphql');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Authorization: Bearer ' . $api_token,
			'Content-Type: application/json',
		]);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $query]));

		$response = curl_exec($ch);
		if (curl_errno($ch)) return ['error' => 'Erro de conexão: ' . curl_error($ch)];
		$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		if ($httpcode !== 200) return ['error' => 'Erro HTTP: Código ' . $httpcode];
		$data = json_decode($response, true);
		if (isset($data['errors'])) return ['error' => 'Erro na API: ' . $data['errors'][0]['message']];
		if (empty($data['data']['viewer']['zones'][0])) return ['error' => 'Zone ID inválido ou sem dados.'];
		return $hourly ? ($data['data']['viewer']['zones'][0]['httpRequests1hGroups'] ?? []) : ($data['data']['viewer']['zones'][0]['httpRequests1dGroups'] ?? []);
	}

}


// Função global para formatar bytes
if (!function_exists('format_bytes_30d')) {
    function format_bytes_30d($bytes) {
        if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2) . ' GB';
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024) return number_format($bytes / 1024, 2) . ' KB';
        return $bytes . ' B';
    }
}

new A7_CloudFlare();