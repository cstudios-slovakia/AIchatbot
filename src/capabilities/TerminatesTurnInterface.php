<?php

namespace cstudiossro\craftcschatbot\capabilities;

/**
 * A capability whose result the visitor receives by other means than the
 * model describing it.
 *
 * The tool-calling loop normally makes a second model call after running a
 * tool, so the model can read the result and answer with it. For an inline
 * form there is nothing to read — the widget renders it from the turn's
 * payload — so when the model has *already* written its reply, that second
 * call is a full completion over the entire prompt spent on nothing.
 *
 * The "already written its reply" part is the whole condition. A model that
 * calls the tool before saying anything has not answered the question yet, and
 * the follow-up call is where its answer gets written; ending the turn there
 * throws the answer away. The loop checks this, not the capability.
 */
interface TerminatesTurnInterface extends CapabilityInterface
{
    /**
     * True when this call needs no follow-up model call to be understood —
     * whatever it produced is already on its way to the visitor.
     *
     * Decided per call, so a capability that only sometimes delivers its own
     * output (a form the visitor fills versus one the model fills) can answer
     * differently each time.
     *
     * Never return true from a capability that fetches data the model has to
     * interpret. The turn may end before it ever sees the result.
     */
    public function terminatesTurn(): bool;
}
