(function () {
    // Atalho do formulario de turno: copia os horarios da segunda-feira para terca a sexta.
    var botao = document.getElementById('copiar-segunda');
    var grade = document.getElementById('grade-turno');
    if (!botao || !grade) {
        return;
    }
    botao.addEventListener('click', function () {
        var campos = ['ent1', 'sai1', 'ent2', 'sai2'];
        var origem = {};
        campos.forEach(function (c) {
            var el = grade.querySelector('[name="h[1][' + c + ']"]');
            origem[c] = el ? el.value : '';
        });
        [2, 3, 4, 5].forEach(function (dow) {
            campos.forEach(function (c) {
                var el = grade.querySelector('[name="h[' + dow + '][' + c + ']"]');
                if (el) {
                    el.value = origem[c];
                }
            });
        });
    });
})();
