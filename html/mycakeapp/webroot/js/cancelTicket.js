var cancelTicket = 0;
$('.takeId').on('click', function () {
    // 最後にクリックしたキャンセルボタンの予約IDを保持
    cancelTicket = $(this).data('id');

    $('#delete').on('click', function () {
        // console.log(cancelTicket);
        $('#delete').attr("href", "ReservationDetails/delete?id=" + cancelTicket)
    });
})
