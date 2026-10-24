// The Novis — página 404: muestra la dirección que no existe, sugiere la página más probable y se pone en gallego en /gl/
(function () {
  var ruta = decodeURI(location.pathname || '/');
  var gl = /^\/gl(\/|$)/.test(ruta);

  // La dirección pedida, en la barra del navegador dibujado (como texto, nunca como HTML)
  var url = document.getElementById('e404-url');
  if (url) {
    var r = ruta.length > 34 ? ruta.slice(0, 33) + '…' : ruta;
    url.textContent = 'thenovis.com' + r;
    url.title = 'thenovis.com' + ruta;
  }

  if (gl) {
    document.documentElement.lang = 'gl';
    document.title = 'Páxina non atopada — The Novis';
    document.querySelectorAll('[data-gl]').forEach(function (el) { el.textContent = el.getAttribute('data-gl'); });
    document.querySelectorAll('[data-gl-href]').forEach(function (el) { el.setAttribute('href', el.getAttribute('data-gl-href')); });
  }

  // ¿Quizás buscabas…? según las palabras de la dirección
  var base = gl ? '/gl/' : '/';
  var opciones = [
    [/precio|prezo|plan|tarifa|coste|cuesta/, base + '#planes', gl ? 'Prezos e plans' : 'Precios y planes'],
    [/contact|presupuesto|orzamento|email|correo/, base + '#contacto', gl ? 'Contacto' : 'Contacto'],
    [/pago|pagar|cuenta|conta|login|entrar|acceso|cliente|area|factura/, '/pagos/', gl ? 'Área de clientes' : 'Área de clientes'],
    [/dato|rgpd|borrar|eliminar/, '/datos/', gl ? 'Os teus datos' : 'Tus datos'],
    [/privac/, '/privacidad.html', gl ? 'Política de privacidade' : 'Política de privacidad'],
    [/legal|aviso/, '/aviso-legal.html', 'Aviso legal'],
    [/cookie/, '/cookies.html', 'Cookies'],
    [/accesib/, '/accesibilidad.html', gl ? 'Accesibilidade' : 'Accesibilidad'],
    [/trabajo|traballo|portfolio|proyecto|proxecto|ejemplo|exemplo/, base + '#trabajos', gl ? 'Exemplos de webs' : 'Ejemplos de webs'],
    [/servicio|servizo|restaurante|reserva|seo|mantenimiento|mantemento/, base + '#servicios', gl ? 'Servizos' : 'Servicios'],
    [/faq|pregunta|duda|dubida/, base + '#faq', gl ? 'Preguntas frecuentes' : 'Preguntas frecuentes']
  ];
  var r2 = ruta.toLowerCase();
  for (var i = 0; i < opciones.length; i++) {
    if (opciones[i][0].test(r2)) {
      var sug = document.getElementById('e404-sug');
      var a = sug && sug.querySelector('a');
      if (a) { a.href = opciones[i][1]; a.textContent = opciones[i][2]; sug.hidden = false; }
      break;
    }
  }
})();
