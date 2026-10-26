(function () {
    var timer;
    function reiniciar() {
        clearTimeout(timer);
        timer = setTimeout(function () {
            window.location.href = '/logout.php';
        }, 60000);
    }
    ['mousemove', 'keydown', 'click', 'touchstart', 'scroll'].forEach(function (evt) {
        document.addEventListener(evt, reiniciar, true);
    });
    reiniciar();
})();
