
jQuery(document).ready(function($) {
    // Handle event creation form
    $("#create-event-form").on("submit", function(e) {
        e.preventDefault();
        
        var formData = $(this).serialize();
        formData += "&action=create_event&nonce=" + party_minder_ajax.nonce;
        
        $.ajax({
            url: party_minder_ajax.ajax_url,
            type: "POST",
            data: formData,
            success: function(response) {
                if (response.success) {
                    $("#event-message").removeClass("error").addClass("success").text(response.data.message).show();
                    $("#create-event-form")[0].reset();
                } else {
                    $("#event-message").removeClass("success").addClass("error").text("Error creating event.").show();
                }
            },
            error: function() {
                $("#event-message").removeClass("success").addClass("error").text("Error creating event.").show();
            }
        });
    });
    
    // Handle invitation request
    $(".request-invite-btn").on("click", function() {
        var eventId = $(this).data("event-id");
        $("#request-event-id").val(eventId);
        $("#invitation-modal").show();
    });
    
    $("#close-modal").on("click", function() {
        $("#invitation-modal").hide();
    });
    
    // Handle RSVP responses
    $(".rsvp-btn").on("click", function() {
        var invitationId = $(this).data("invitation-id");
        var response = $(this).data("response");
        
        $.ajax({
            url: party_minder_ajax.ajax_url,
            type: "POST",
            data: {
                action: "rsvp_response",
                invitation_id: invitationId,
                response: response,
                nonce: party_minder_ajax.nonce
            },
            success: function(result) {
                if (result.success) {
                    location.reload();
                } else {
                    alert("Error updating RSVP.");
                }
            },
            error: function() {
                alert("Error updating RSVP.");
            }
        });
    });
    
    // Close modal when clicking outside
    $("#invitation-modal").on("click", function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });
});
