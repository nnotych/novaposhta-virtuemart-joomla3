<?php
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Response\JsonResponse;

class plgSystemNovaposhta extends JPlugin
{
    public function onAjaxNovaposhta()
    {
        $app = Factory::getApplication();
        $input = $app->input;

   
        if (!\JSession::checkToken('request')) {
            echo new JsonResponse(null, 'Invalid token', true);
            $app->close();
        }


        $raw = file_get_contents('php://input');
        if (!$raw) {
            echo new JsonResponse(null, 'Empty request', true);
            $app->close();
        }

        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            echo new JsonResponse(null, 'Invalid JSON', true);
            $app->close();
        }

        $apiKey = trim((string)$this->params->get('api_key', ''));
        if ($apiKey === '') {
            echo new JsonResponse(null, 'API key not configured', true);
            $app->close();
        }

        // whitelist Methods
        $allowedMethods = ['getCities', 'getWarehouses', 'getServices'];
        $method = isset($payload['calledMethod']) ? (string)$payload['calledMethod'] : '';

        if (!in_array($method, $allowedMethods, true)) {
            echo new JsonResponse(null, 'Method not allowed', true);
            $app->close();
        }

        // Rate limiting
        $session = $app->getSession();
        $lastRequest = (int)$session->get('novaposhta_last_request', 0);

        if ((time() - $lastRequest) < 1) {
            echo new JsonResponse(null, 'Too many requests', true);
            $app->close();
        }
        $session->set('novaposhta_last_request', time());

        // payload (логіка НЕ змінена)
        $body = [
            'apiKey' => $apiKey,
            'modelName' => isset($payload['modelName']) ? substr(trim((string)$payload['modelName']), 0, 50) : '',
            'calledMethod' => $method,
            'methodProperties' => []
        ];

        if (isset($payload['methodProperties']) && is_array($payload['methodProperties'])) {
            foreach ($payload['methodProperties'] as $key => $value) {

           
                if (!is_scalar($value)) {
                    continue;
                }

                if (in_array($key, ['FindByString', 'CityRef', 'Limit'], true)) {
                    $body['methodProperties'][$key] = substr(trim((string)$value), 0, 100);
                }
            }
        }

        // cURL secure
        $ch = curl_init('https://api.novaposhta.ua/v2.0/json/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXREDIRS => 0,
        ]);

        $resp = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            Log::add('Nova Poshta API error: ' . $err, Log::ERROR, 'plg_system_novaposhta');
            echo new JsonResponse(null, 'API connection error', true);
            $app->close();
        }

        $data = json_decode($resp, true);

        if (!is_array($data)) {
            Log::add('Invalid API response: ' . substr($resp, 0, 300), Log::ERROR, 'plg_system_novaposhta');
            echo new JsonResponse(null, 'Invalid API response', true);
            $app->close();
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        $app->close();
    }

    public function onAfterRender()
    {
        $app = Factory::getApplication();

        if ($app->isClient('administrator')) {
            return;
        }

        $input = $app->input;
        $option = $input->getCmd('option', '');
        $view = $input->getCmd('view', '');

        if (!($option === 'com_virtuemart' && ($view === 'cart' || $view === 'checkout'))) {
            return;
        }

        $novaMethodId = (int)$this->params->get('nova_method_id', 0);
        $token = $app->getSession()->getFormToken();
        $html = <<<'HTML'
<div id="novaposhta_autocomplete_block" style="display:none; margin:15px 0; padding:15px; background:#f9f9f9; border:1px solid #ddd; border-radius:6px;">
  <h3 style="margin:0 0 15px 0; font-size:18px; color:#333;">Нова Пошта — виберіть місто та відділення</h3>
  <div class="autocomplete" style="margin-bottom:15px;">
    <input type="text" id="np_city_input" placeholder="Введіть назву міста" autocomplete="off" style="padding:10px; width:100%; box-sizing:border-box; border:1px solid #ccc; border-radius:4px; font-size:14px;" />
    <div id="np_city_suggestions" class="suggestions" style="position:relative; z-index:9999; margin-top:5px; max-height:300px; overflow-y:auto;"></div>
  </div>
  <ul id="np_warehouses" style="list-style:none; padding:0; margin:0; max-height:300px; overflow-y:auto; border:1px solid #ddd; border-radius:4px;"></ul>
</div>

<script>
(function(){
  try {
    var novaMethodId = '__NOVA_METHOD_ID__';
    var token = '__TOKEN__';

    function escapeHtml(text) {
      var div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }

    function getField(name) {
      var elem = document.querySelector('input[name="' + name.replace(/"/g, '\\"') + '"]');
      if (!elem) {
        elem = document.querySelector('textarea[name="' + name.replace(/"/g, '\\"') + '"]');
      }
      return elem || null;
    }

    function checkShipment() {
      var radios = document.querySelectorAll('input[name="virtuemart_shipmentmethod_id"]');
      var show = false;
      
      for (var i = 0; i < radios.length; i++){
        if (radios[i].checked && String(radios[i].value) === String(novaMethodId)) { 
          show = true; 
          break; 
        }
      }
      
      var block = document.getElementById('novaposhta_autocomplete_block');
      if (block) {
        block.style.display = show ? 'block' : 'none';
      }

      if (!show) {
        clearFields();
      }
    }

    function clearFields() {
      var fDesc = getField('np_warehouse_desc');
      var fRef = getField('np_warehouse_ref');
      
      if (fDesc) { fDesc.value = ''; }
      if (fRef) { fRef.value = ''; }
    }

    function closeSuggestions() {
      var citySuggestions = document.getElementById('np_city_suggestions');
      if (citySuggestions) citySuggestions.innerHTML = '';
    }

    function closeWarehouses() {
      var warehousesList = document.getElementById('np_warehouses');
      if (warehousesList) warehousesList.innerHTML = '';
    }

    document.addEventListener('change', function(e){
      if (e.target && e.target.name === 'virtuemart_shipmentmethod_id') {
        checkShipment();
      }
    });

    var cityTimer = null;

    function attachAutocomplete() {
      var cityInput = document.getElementById('np_city_input');
      if (cityInput) {
  cityInput.addEventListener('focus', function () {

    var ref = this.dataset.cityRef;
    var name = this.dataset.cityName;

    if (ref && name) {
      loadWarehouses(ref, name);
    }
  });
}
      cityInput.addEventListener('input', function(){
        clearTimeout(cityTimer);
        var citySuggestions = document.getElementById('np_city_suggestions');
        var warehousesList = document.getElementById('np_warehouses');
        
        if (citySuggestions) citySuggestions.innerHTML = '';
        if (warehousesList) warehousesList.innerHTML = '';
        
        var q = cityInput.value.trim();
        if (q.length < 2 || q.length > 100) return;
        
        cityTimer = setTimeout(function(){ 
          searchCities(q); 
        }, 400);
      });

      document.addEventListener('click', function(e){
        var block = document.getElementById('novaposhta_autocomplete_block');
        if (!block || !block.contains(e.target)) {
          closeSuggestions();
          closeWarehouses();
        }
      });
    }

    function searchCities(query) {
      var params = new URLSearchParams();
      params.append('option', 'com_ajax');
      params.append('plugin', 'novaposhta');
      params.append('format', 'json');
      params.append(token, token);

      var payload = { 
        modelName: 'Address', 
        calledMethod: 'getCities', 
        methodProperties: { FindByString: query } 
      };
      
      fetch('index.php?' + params.toString(), {
        method: 'POST', 
        headers: {'Content-Type':'application/json'}, 
        body: JSON.stringify(payload)
      }).then(function(r){ return r.json(); })
       .then(function(resp){
        if (!resp.data || !Array.isArray(resp.data)) return;
        
        var list = resp.data;
        var citySuggestions = document.getElementById('np_city_suggestions');
        if (!citySuggestions) return;
        
        citySuggestions.innerHTML = '';
        if (!list.length) { 
          citySuggestions.innerHTML = '<div style="padding:10px; color:#999;">Міста не знайдено</div>'; 
          return; 
        }
        
        list.slice(0, 20).forEach(function(city){
          if (!city.Ref || !city.Description) return;
          
          var txt = city.AreaDescription 
            ? escapeHtml(city.Description) + ' (' + escapeHtml(city.AreaDescription) + ')' 
            : escapeHtml(city.Description);
          var div = document.createElement('div');
          div.innerHTML = txt;
          div.style.padding = '10px';
          div.style.cursor = 'pointer';
          div.style.borderBottom = '1px solid #eee';
          div.style.backgroundColor = '#fff';
          div.style.transition = 'background-color 0.2s';
          div.dataset.ref = city.Ref;
          div.dataset.desc = city.Description;
          
          div.addEventListener('mouseover', function(){ 
            this.style.backgroundColor = '#f0f8ff'; 
          });
          div.addEventListener('mouseout', function(){ 
            this.style.backgroundColor = '#fff'; 
          });
          div.addEventListener('click', function(){

  var input = document.getElementById('np_city_input');
  if (input) input.value = city.Description;

  closeSuggestions();

  //  Зберігаємо вибране місто в інпуті
  input.dataset.cityRef = city.Ref;
  input.dataset.cityName = city.Description;

  loadWarehouses(city.Ref, city.Description);
});
          
          citySuggestions.appendChild(div);
        });
      }).catch(function(e){ 
        console.error('novaposhta searchCities error', e); 
      });
    }

     function loadWarehouses(cityRef, cityName) {

  var warehousesList = document.getElementById('np_warehouses');
  if (!warehousesList) return;

  var cacheKey = 'np_wh_' + cityRef;
  var cacheTimeKey = cacheKey + '_time';

  var now = Date.now();
  var cache = localStorage.getItem(cacheKey);
  var cacheTime = localStorage.getItem(cacheTimeKey);


  var CACHE_LIFETIME = 86400000;

  // =========================
  // 1. ПЕРЕВІРКА КЕШУ
  // =========================
  if (cache && cacheTime && (now - cacheTime < CACHE_LIFETIME)) {

    var data = JSON.parse(cache);

    render(data);
    return;
  }

  // =========================
  // 2. API ЗАПИТ
  // =========================
  warehousesList.innerHTML = '<li style="padding:10px; text-align:center; color:#999;">Завантаження...</li>';

  var params = new URLSearchParams();
  params.append('option', 'com_ajax');
  params.append('plugin', 'novaposhta');
  params.append('format', 'json');
  params.append(token, token);

  fetch('index.php?' + params.toString(), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      modelName: 'Address',
      calledMethod: 'getWarehouses',
      methodProperties: {
        CityRef: cityRef,
        Limit: 500
      }
    })
  })
  .then(r => r.json())
  .then(function(resp) {

    if (!resp || !resp.data) {
      warehousesList.innerHTML = '<li style="padding:10px;">Немає даних</li>';
      return;
    }


    localStorage.setItem(cacheKey, JSON.stringify(resp.data));
    localStorage.setItem(cacheTimeKey, now);

    render(resp.data);
  })
  .catch(function(err) {
    console.error(err);
    warehousesList.innerHTML = '<li style="padding:10px; color:red;">Помилка завантаження</li>';
  });

  // =========================
  // 3. RENDER
  // =========================
  function render(list) {

    warehousesList.innerHTML = '';

    if (!list.length) {
      warehousesList.innerHTML = '<li style="padding:10px;">Немає відділень</li>';
      return;
    }

    list.forEach(function(wh) {

      if (!wh.Ref || !wh.Description) return;

      var li = document.createElement('li');

      li.textContent = wh.Description + (wh.ShortAddress ? ' — ' + wh.ShortAddress : '');

      li.style.padding = '10px';
      li.style.cursor = 'pointer';
      li.style.borderBottom = '1px solid #eee';

      li.dataset.ref = wh.Ref;
      li.dataset.desc = wh.Description;

      li.onclick = function() {
        selectWarehouse(li, cityName);
      };

      warehousesList.appendChild(li);
    });
  }
}

    function selectWarehouse(li, cityName) {
      var desc = li.dataset.desc;
      var ref = li.dataset.ref;
      
      if (!desc || !ref) return;
      
      var fDesc = getField('np_warehouse_desc');
      var fRef = getField('np_warehouse_ref');
      
      if (fDesc) { 
        fDesc.value = desc;
        fDesc.dispatchEvent(new Event('change', {bubbles: true}));
      }
      if (fRef) { 
        fRef.value = ref;
        fRef.dispatchEvent(new Event('change', {bubbles: true}));
      }
      
      var siblings = li.parentNode ? li.parentNode.children : [];
      for (var i = 0; i < siblings.length; i++){ 
        if(siblings[i]) {
          siblings[i].style.background = '#fff';
          siblings[i].classList.remove('selected');
        }
      }
      
      li.style.background = '#e3f2fd';
      li.classList.add('selected');
      
      setTimeout(function(){
        closeWarehouses();
        var warehousesList = document.getElementById('np_warehouses');
        if (warehousesList) {
          warehousesList.innerHTML = '<li style="padding:10px; font-weight:bold; color:#4CAF50;">✓ ' + escapeHtml(desc) + '</li>';
        }
      }, 200);
    }

    document.addEventListener('DOMContentLoaded', function(){
      setTimeout(function(){
        checkShipment();
        attachAutocomplete();
      }, 300);
    });

    var observer = new MutationObserver(function(){
      checkShipment();
    });
    observer.observe(document.body, { childList: true, subtree: true });

  } catch (err) {
    console.error('novaposhta init error', err);
  }
})();
</script>
HTML;

        $js = str_replace(
            ['__NOVA_METHOD_ID__', '__TOKEN__'],
            [$novaMethodId, $token],
            $html
        );

        try {
            $body = $app->getBody();
            $fieldset_end = '</fieldset>';
            $pos = strpos($body, $fieldset_end);
            
            if ($pos !== false) {
                $pos += strlen($fieldset_end);
                $body = substr_replace($body, $js, $pos, 0);
            } else if (strpos($body, '</form>') !== false) {
                $body = preg_replace('/<\/form>/', $js . '</form>', $body, 1);
            } else if (strpos($body, '</body>') !== false) {
                $body = str_replace('</body>', $js . '</body>', $body);
            } else {
                $body .= $js;
            }

            $app->setBody($body);
        } catch (Exception $e) {
            Log::add('novaposhta error: ' . $e->getMessage(), Log::ERROR, 'plg_system_novaposhta');
        }
    }
}
