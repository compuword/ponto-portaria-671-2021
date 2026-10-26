(function () {
    // 5 minutos sem atividade. Mantenha em sincronia com
    // PONTO_LIMITE_INATIVIDADE_SEG em src/config.php (a checagem valida e a do servidor).
    var LIMITE_MS = 5 * 60 * 1000;
    var timer;
    function reiniciar() {
        clearTimeout(timer);
        timer = setTimeout(function () {
            window.location.href = '/logout.php';
        }, LIMITE_MS);
    }
    ['mousemove', 'keydown', 'click', 'touchstart', 'scroll'].forEach(function (evt) {
        document.addEventListener(evt, reiniciar, true);
    });
    reiniciar();
})();
